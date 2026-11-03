<?php

namespace Tests\Feature\Storage;

use App\Jobs\DeleteStoredFile;
use App\Models\ActivityLog;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OrderTicket;
use App\Models\OrderTicketPhoto;
use App\Models\Pallet;
use App\Models\PalletBase;
use App\Models\PalletBasePhoto;
use App\Models\PalletPhoto;
use App\Models\PendingFileDeletion;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Los borrados administrativos (admin/storage) no ejecutan I/O de
 * storage con la transacción abierta: crean intenciones durables dentro
 * de la tx y el borrado físico corre post-commit vía DeleteStoredFile
 * (D-018/D-027). Una entidad `done` responde 409 sin efectos (D-031).
 */
class AdminStorageDeletionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['filesystems.default' => 'public']);
        Storage::fake('public');
    }

    private function admin(): User
    {
        return User::factory()->withRole('admin')->create();
    }

    private function palletConArchivos(string $status = 'open'): array
    {
        $pallet = Pallet::factory()->create(['status' => $status]);
        $base = PalletBase::factory()->for($pallet, 'pallet')->create();

        $palletPhotoPath = "pallets/{$pallet->id}/p.webp";
        $basePhotoPath = "pallets/{$pallet->id}/bases/{$base->id}/b.webp";
        Storage::disk('public')->put($palletPhotoPath, 'x');
        Storage::disk('public')->put($basePhotoPath, 'x');

        $palletPhoto = PalletPhoto::create([
            'pallet_id' => $pallet->id,
            'path' => $palletPhotoPath,
            'original_name' => 'p.webp',
        ]);
        $basePhoto = PalletBasePhoto::create([
            'base_id' => $base->id,
            'path' => $basePhotoPath,
            'original_name' => 'b.webp',
        ]);

        return [$pallet, $base, [$palletPhotoPath, $basePhotoPath], [$palletPhoto, $basePhoto]];
    }

    private function orderConArchivos(string $status = 'open'): array
    {
        $order = Order::factory()->create(['status' => $status]);
        OrderItem::factory()->for($order)->create();
        $ticket = OrderTicket::create(['order_id' => $order->id, 'code' => 'T-1']);

        $path = "orders/{$order->id}/tickets/{$ticket->id}/t.webp";
        Storage::disk('public')->put($path, 'x');
        $photo = OrderTicketPhoto::create([
            'ticket_id' => $ticket->id,
            'path' => $path,
            'original_name' => 't.webp',
        ]);

        return [$order, $ticket, $photo, $path];
    }

    // ── done → 409 y cero efectos ────────────────────────────────────────

    public function test_pallet_done_409_sin_efectos(): void
    {
        [$pallet, $base, $paths, $photos] = $this->palletConArchivos('done');

        $this->actingAs($this->admin())
            ->deleteJson("/api/v1/admin/storage/pallets/{$pallet->id}")
            ->assertConflict();

        $this->assertNotNull($pallet->fresh());
        $this->assertNotNull($base->fresh());
        foreach ($photos as $photo) {
            $this->assertNotNull($photo->fresh());
        }
        foreach ($paths as $path) {
            Storage::disk('public')->assertExists($path);
        }
        $this->assertDatabaseCount('pending_file_deletions', 0);
        $this->assertDatabaseMissing('activity_logs', ['action' => 'admin_pallet_deleted']);
    }

    public function test_pedido_done_409_sin_efectos(): void
    {
        [$order, $ticket, $photo, $path] = $this->orderConArchivos('done');

        $this->actingAs($this->admin())
            ->deleteJson("/api/v1/admin/storage/orders/{$order->id}")
            ->assertConflict();

        $this->assertNotNull($order->fresh());
        $this->assertNotNull($ticket->fresh());
        $this->assertNotNull($photo->fresh());
        Storage::disk('public')->assertExists($path);
        $this->assertDatabaseCount('pending_file_deletions', 0);
        $this->assertDatabaseMissing('activity_logs', ['action' => 'admin_order_deleted']);
    }

    // ── abierto → filas eliminadas + intención durable por archivo ───────

    public function test_pallet_abierto_elimina_filas_y_registra_intencion_por_archivo(): void
    {
        [$pallet, $base, $paths] = $this->palletConArchivos();

        // Queue::fake evita el dispatch post-commit: las intenciones
        // quedan pendientes y el filesystem nunca fue invocado dentro de
        // la transacción (los archivos siguen existiendo al responder).
        Queue::fake();

        $this->actingAs($this->admin())
            ->deleteJson("/api/v1/admin/storage/pallets/{$pallet->id}")
            ->assertOk()
            ->assertJsonPath('deleted', true)
            ->assertJsonPath('code', $pallet->code);

        $this->assertNull($pallet->fresh());
        $this->assertNull($base->fresh());
        $this->assertSame(0, PalletPhoto::count());
        $this->assertSame(0, PalletBasePhoto::count());

        // Cero I/O durante la tx: los archivos aún existen y el job no corrió.
        foreach ($paths as $path) {
            Storage::disk('public')->assertExists($path);
        }
        Queue::assertPushed(DeleteStoredFile::class, 2);
        $this->assertEqualsCanonicalizing(
            $paths,
            PendingFileDeletion::whereNull('completed_at')->pluck('path')->all(),
        );
        $this->assertEqualsCanonicalizing(
            ['admin_pallet_deleted', 'admin_pallet_deleted'],
            PendingFileDeletion::pluck('reason')->all(),
        );

        $log = ActivityLog::where('action', 'admin_pallet_deleted')->sole();
        $this->assertSame($pallet->id, $log->entity_id);
    }

    public function test_pedido_abierto_elimina_filas_y_registra_intencion_por_archivo(): void
    {
        [$order, $ticket, $photo, $path] = $this->orderConArchivos();
        Queue::fake();

        $this->actingAs($this->admin())
            ->deleteJson("/api/v1/admin/storage/orders/{$order->id}")
            ->assertOk()
            ->assertJsonPath('deleted', true);

        $this->assertNull($order->fresh());
        $this->assertNull($ticket->fresh());
        $this->assertNull($photo->fresh());

        Storage::disk('public')->assertExists($path);
        Queue::assertPushed(DeleteStoredFile::class, 1);
        $intent = PendingFileDeletion::sole();
        $this->assertSame($path, $intent->path);
        $this->assertSame('admin_order_deleted', $intent->reason);
        $this->assertNull($intent->completed_at);

        $log = ActivityLog::where('action', 'admin_order_deleted')->sole();
        $this->assertSame($order->id, $log->entity_id);
        $this->assertSame('order', $log->entity_type);
    }

    // ── fallo/crash post-commit: la intención se recupera por el barrido ─

    public function test_intencion_pendiente_de_admin_delete_se_recupera_por_el_barrido(): void
    {
        [$pallet, , $paths] = $this->palletConArchivos();
        Queue::fake(); // simula crash entre commit y dispatch

        $this->actingAs($this->admin())
            ->deleteJson("/api/v1/admin/storage/pallets/{$pallet->id}")
            ->assertOk();

        $this->assertSame(2, PendingFileDeletion::whereNull('completed_at')->count());

        $this->artisan('storage:retry-pending-deletions')->assertExitCode(0);

        $this->assertSame(0, PendingFileDeletion::whereNull('completed_at')->count());
        foreach ($paths as $path) {
            Storage::disk('public')->assertMissing($path);
        }
    }

    // ── reintento seguro: repetir el barrido o el borrado es inofensivo ──

    public function test_reintento_es_seguro_e_idempotente(): void
    {
        [$pallet, , $paths] = $this->palletConArchivos();
        Queue::fake();

        $this->actingAs($this->admin())
            ->deleteJson("/api/v1/admin/storage/pallets/{$pallet->id}")
            ->assertOk();

        // Repetir el borrado: la entidad ya no existe → 404, sin efectos.
        $this->actingAs($this->admin())
            ->deleteJson("/api/v1/admin/storage/pallets/{$pallet->id}")
            ->assertNotFound();
        $this->assertSame(2, PendingFileDeletion::count());

        // El barrido corre dos veces sin efectos incorrectos.
        $this->artisan('storage:retry-pending-deletions')->assertExitCode(0);
        $this->artisan('storage:retry-pending-deletions')->assertExitCode(0);
        $this->assertSame(0, PendingFileDeletion::whereNull('completed_at')->count());
        foreach ($paths as $path) {
            Storage::disk('public')->assertMissing($path);
        }
    }
}
