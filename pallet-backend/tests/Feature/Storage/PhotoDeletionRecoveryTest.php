<?php

namespace Tests\Feature\Storage;

use App\Models\Order;
use App\Models\OrderTicket;
use App\Models\OrderTicketPhoto;
use App\Models\Pallet;
use App\Models\PalletBase;
use App\Models\PalletBasePhoto;
use App\Models\PalletPhoto;
use App\Models\PendingFileDeletion;
use App\Models\User;
use App\Services\StorageCleanupService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Mockery;
use RuntimeException;
use Tests\TestCase;

class PhotoDeletionRecoveryTest extends TestCase
{
    use RefreshDatabase;

    // Con cola sync el job DeleteStoredFile corre dentro del callback
    // post-commit del request: al volver, las intenciones ya están
    // completadas y los archivos borrados. storage:retry-pending-deletions
    // queda como camino de recuperación (crash entre commit y dispatch,
    // storage caído) y se verifica también como no-op sobre completadas.

    protected function setUp(): void
    {
        parent::setUp();

        config(['filesystems.default' => 'public']);
        Storage::fake('public');
    }

    // ── 1. Fallo de auditoría: nada se borra, nada queda registrado ──────

    public function test_foto_de_pallet_sin_auditoria_no_pierde_fila_ni_archivo(): void
    {
        $user = User::factory()->withRole()->create();
        $pallet = Pallet::factory()->create();
        $photo = $this->fotoDePallet($pallet, "pallets/{$pallet->id}/a.webp");
        $this->failActivityLogs();

        $this->actingAs($user)
            ->delete("/api/v1/pallets/{$pallet->id}/photos/{$photo->id}")
            ->assertStatus(500);

        $this->assertDatabaseHas('pallet_photos', ['id' => $photo->id]);
        Storage::disk('public')->assertExists($photo->path);
        $this->assertDatabaseCount('pending_file_deletions', 0);
    }

    public function test_foto_de_base_sin_auditoria_no_pierde_fila_ni_archivo(): void
    {
        $user = User::factory()->withRole()->create();
        $base = PalletBase::factory()->create();
        $photo = $this->fotoDeBase($base, "pallets/{$base->pallet_id}/bases/{$base->id}/a.webp");
        $this->failActivityLogs();

        $this->actingAs($user)
            ->delete("/api/v1/pallets/{$base->pallet_id}/bases/{$base->id}/photos/{$photo->id}")
            ->assertStatus(500);

        $this->assertDatabaseHas('pallet_base_photos', ['id' => $photo->id]);
        Storage::disk('public')->assertExists($photo->path);
        $this->assertDatabaseCount('pending_file_deletions', 0);
    }

    public function test_foto_de_ticket_sin_auditoria_no_pierde_fila_ni_archivo(): void
    {
        $user = User::factory()->withRole()->create();
        $ticket = OrderTicket::create(['order_id' => Order::factory()->create()->id, 'code' => 'T-1']);
        $photo = $this->fotoDeTicket($ticket, "orders/{$ticket->order_id}/tickets/{$ticket->id}/a.webp");
        $this->failActivityLogs();

        $this->actingAs($user)
            ->delete("/api/v1/orders/{$ticket->order_id}/tickets/{$ticket->id}/photos/{$photo->id}")
            ->assertStatus(500);

        $this->assertDatabaseHas('order_ticket_photos', ['id' => $photo->id]);
        Storage::disk('public')->assertExists($photo->path);
        $this->assertDatabaseCount('pending_file_deletions', 0);
    }

    public function test_ticket_sin_auditoria_no_pierde_ticket_ni_fotos_ni_archivos(): void
    {
        $user = User::factory()->withRole()->create();
        $ticket = OrderTicket::create(['order_id' => Order::factory()->create()->id, 'code' => 'T-1']);
        $photos = collect(range(1, 3))->map(
            fn ($i) => $this->fotoDeTicket($ticket, "orders/{$ticket->order_id}/tickets/{$ticket->id}/{$i}.webp")
        );
        $this->failActivityLogs();

        $this->actingAs($user)
            ->delete("/api/v1/orders/{$ticket->order_id}/tickets/{$ticket->id}")
            ->assertStatus(500);

        $this->assertDatabaseHas('order_tickets', ['id' => $ticket->id]);
        $this->assertDatabaseCount('order_ticket_photos', 3);
        foreach ($photos as $photo) {
            Storage::disk('public')->assertExists($photo->path);
        }
        $this->assertDatabaseCount('pending_file_deletions', 0);
    }

    // ── 2. Camino feliz: entidad + auditoría + intención por archivo ──────

    public function test_borrar_foto_de_pallet_registra_intencion_y_el_barrido_elimina_el_archivo(): void
    {
        $user = User::factory()->withRole()->create();
        $pallet = Pallet::factory()->create();
        $photo = $this->fotoDePallet($pallet, "pallets/{$pallet->id}/a.webp");

        $this->actingAs($user)
            ->delete("/api/v1/pallets/{$pallet->id}/photos/{$photo->id}")
            ->assertOk();

        $this->assertDatabaseMissing('pallet_photos', ['id' => $photo->id]);
        $this->assertDatabaseHas('activity_logs', ['action' => 'pallet_photo_deleted']);

        $intent = PendingFileDeletion::sole();
        $this->assertSame('public', $intent->disk);
        $this->assertSame($photo->path, $intent->path);
        $this->assertSame('pallet_photo_deleted', $intent->reason);

        // Cola sync: el job corrió tras el commit dentro del request.
        $this->assertNotNull($intent->completed_at);
        Storage::disk('public')->assertMissing($photo->path);

        // El barrido sobre intenciones completadas es un no-op exitoso.
        $this->artisan('storage:retry-pending-deletions')->assertExitCode(0);
    }

    public function test_borrar_foto_de_base_registra_intencion_y_el_barrido_elimina_el_archivo(): void
    {
        $user = User::factory()->withRole()->create();
        $base = PalletBase::factory()->create();
        $photo = $this->fotoDeBase($base, "pallets/{$base->pallet_id}/bases/{$base->id}/a.webp");

        $this->actingAs($user)
            ->delete("/api/v1/pallets/{$base->pallet_id}/bases/{$base->id}/photos/{$photo->id}")
            ->assertOk();

        $this->assertDatabaseMissing('pallet_base_photos', ['id' => $photo->id]);
        $this->assertDatabaseHas('activity_logs', ['action' => 'base_photo_deleted']);

        $intent = PendingFileDeletion::sole();
        $this->assertSame('public', $intent->disk);
        $this->assertSame($photo->path, $intent->path);
        $this->assertSame('base_photo_deleted', $intent->reason);
        $this->assertNotNull($intent->completed_at);
        Storage::disk('public')->assertMissing($photo->path);
    }

    public function test_borrar_foto_de_ticket_registra_intencion_y_el_barrido_elimina_el_archivo(): void
    {
        $user = User::factory()->withRole()->create();
        $ticket = OrderTicket::create(['order_id' => Order::factory()->create()->id, 'code' => 'T-1']);
        $photo = $this->fotoDeTicket($ticket, "orders/{$ticket->order_id}/tickets/{$ticket->id}/a.webp");

        $this->actingAs($user)
            ->delete("/api/v1/orders/{$ticket->order_id}/tickets/{$ticket->id}/photos/{$photo->id}")
            ->assertOk();

        $this->assertDatabaseMissing('order_ticket_photos', ['id' => $photo->id]);
        $this->assertDatabaseHas('activity_logs', ['action' => 'order_ticket_photo_deleted']);

        $intent = PendingFileDeletion::sole();
        $this->assertSame('public', $intent->disk);
        $this->assertSame($photo->path, $intent->path);
        $this->assertSame('order_ticket_photo_deleted', $intent->reason);
        $this->assertNotNull($intent->completed_at);
        Storage::disk('public')->assertMissing($photo->path);
    }

    public function test_borrar_ticket_registra_una_intencion_por_foto(): void
    {
        $user = User::factory()->withRole()->create();
        $ticket = OrderTicket::create(['order_id' => Order::factory()->create()->id, 'code' => 'T-1']);
        $photos = collect(range(1, 3))->map(
            fn ($i) => $this->fotoDeTicket($ticket, "orders/{$ticket->order_id}/tickets/{$ticket->id}/{$i}.webp")
        );

        $this->actingAs($user)
            ->delete("/api/v1/orders/{$ticket->order_id}/tickets/{$ticket->id}")
            ->assertOk();

        $this->assertDatabaseMissing('order_tickets', ['id' => $ticket->id]);
        $this->assertDatabaseCount('order_ticket_photos', 0);
        $this->assertDatabaseHas('activity_logs', ['action' => 'order_ticket_deleted']);

        // Una intención por archivo, ni una más ni una menos.
        $intents = PendingFileDeletion::all();
        $this->assertCount(3, $intents);
        $this->assertSame(
            $photos->pluck('path')->sort()->values()->all(),
            $intents->pluck('path')->sort()->values()->all(),
        );
        $this->assertSame(['order_ticket_deleted'], $intents->pluck('reason')->unique()->values()->all());
        $this->assertSame(['public'], $intents->pluck('disk')->unique()->values()->all());

        foreach ($photos as $photo) {
            Storage::disk('public')->assertMissing($photo->path);
        }
        $this->assertSame(3, PendingFileDeletion::whereNotNull('completed_at')->count());
    }

    // ── 4/5. Fallo de storage y recuperación por barrido ─────────────────

    public function test_fallo_de_storage_deja_intencion_pendiente_que_el_barrido_recupera(): void
    {
        $user = User::factory()->withRole()->create();
        $pallet = Pallet::factory()->create();
        $photo = $this->fotoDePallet($pallet, "pallets/{$pallet->id}/a.webp");

        $nivelBase = DB::transactionLevel();
        $discoReal = Storage::disk('public');
        $nivelesEnDelete = [];

        $doble = Mockery::mock($discoReal)->makePartial();
        $doble->shouldReceive('delete')->andReturnUsing(function () use (&$nivelesEnDelete) {
            $nivelesEnDelete[] = DB::transactionLevel();

            throw new RuntimeException('storage caído');
        });
        Storage::set('public', $doble);

        // El borrado lógico y la auditoría confirman aunque storage falle:
        // el job corre tras el commit y su excepción no afecta la respuesta.
        $this->actingAs($user)
            ->delete("/api/v1/pallets/{$pallet->id}/photos/{$photo->id}")
            ->assertOk();

        $this->assertDatabaseMissing('pallet_photos', ['id' => $photo->id]);
        $this->assertDatabaseHas('activity_logs', ['action' => 'pallet_photo_deleted']);

        $intent = PendingFileDeletion::sole();
        $this->assertSame(1, $intent->attempts);
        $this->assertSame(RuntimeException::class, $intent->last_error);
        $this->assertNull($intent->completed_at);

        // El borrado físico corrió sin transacción de aplicación abierta:
        // el nivel observado es el mismo baseline del test (RefreshDatabase).
        $this->assertSame([$nivelBase], $nivelesEnDelete);

        // Un reintento con storage caído vuelve a fallar y reprograma.
        $this->travel(3)->minutes(); // backoff de 2^1 = 2 minutos tras 1 fallo
        $counts = app(StorageCleanupService::class)->retryPending(100, false);
        $this->assertSame(1, $counts['failed']);
        $this->assertSame(2, $intent->fresh()->attempts);

        // Recuperación: storage vuelve y el barrido confirma la intención.
        Storage::set('public', $discoReal);
        $this->travel(5)->minutes(); // backoff de 2^2 = 4 minutos tras 2 fallos

        $this->artisan('storage:retry-pending-deletions')->assertExitCode(0);

        $this->assertNotNull($intent->fresh()->completed_at);
        Storage::disk('public')->assertMissing($photo->path);
    }

    public function test_delete_false_con_objeto_presente_tambien_deja_intencion_pendiente(): void
    {
        $user = User::factory()->withRole()->create();
        $pallet = Pallet::factory()->create();
        $photo = $this->fotoDePallet($pallet, "pallets/{$pallet->id}/a.webp");

        $doble = Mockery::mock(Storage::disk('public'))->makePartial();
        $doble->shouldReceive('delete')->andReturn(false);
        Storage::set('public', $doble);

        $this->actingAs($user)
            ->delete("/api/v1/pallets/{$pallet->id}/photos/{$photo->id}")
            ->assertOk();

        $this->assertDatabaseMissing('pallet_photos', ['id' => $photo->id]);

        $intent = PendingFileDeletion::sole();
        $this->assertSame(1, $intent->attempts);
        $this->assertSame('delete_returned_false', $intent->last_error);
        $this->assertNull($intent->completed_at);
    }

    // ── 6/7. Idempotencia y archivo inexistente ──────────────────────────

    public function test_reprocesar_una_intencion_completada_es_noop(): void
    {
        $user = User::factory()->withRole()->create();
        $pallet = Pallet::factory()->create();
        $photo = $this->fotoDePallet($pallet, "pallets/{$pallet->id}/a.webp");

        $this->actingAs($user)
            ->delete("/api/v1/pallets/{$pallet->id}/photos/{$photo->id}")
            ->assertOk();

        $service = app(StorageCleanupService::class);
        $intent = PendingFileDeletion::sole();

        $this->assertTrue($service->process($intent->id));
        $this->assertTrue($service->process($intent->id));

        $intent->refresh();
        $this->assertNotNull($intent->completed_at);
        $this->assertSame(0, $intent->attempts);

        // El barrido repetido no encuentra pendientes ni falla.
        $this->artisan('storage:retry-pending-deletions')->assertExitCode(0);
        $this->artisan('storage:retry-pending-deletions')->assertExitCode(0);
    }

    public function test_intencion_sobre_archivo_inexistente_se_completa(): void
    {
        $intent = PendingFileDeletion::create([
            'disk' => 'public',
            'path' => 'inexistente.webp',
            'reason' => 'pallet_photo_deleted',
            'available_at' => now(),
        ]);

        $this->assertTrue(app(StorageCleanupService::class)->process($intent->id));
        $this->assertNotNull($intent->fresh()->completed_at);
    }

    // ── 8. Intenciones agotadas ──────────────────────────────────────────

    public function test_intencion_agotada_se_omite_y_se_alert_como_critica(): void
    {
        Storage::disk('public')->put('agotada.webp', 'bytes');
        $intent = PendingFileDeletion::create([
            'disk' => 'public',
            'path' => 'agotada.webp',
            'reason' => 'pallet_photo_deleted',
            'attempts' => StorageCleanupService::MAX_ATTEMPTS,
            'available_at' => now(),
        ]);

        Log::spy();

        $this->artisan('storage:retry-pending-deletions')->assertExitCode(0);

        $this->assertNull($intent->fresh()->completed_at);
        Storage::disk('public')->assertExists('agotada.webp');
        Log::shouldHaveReceived('critical');

        $this->artisan('storage:retry-pending-deletions', ['--include-exhausted' => true])
            ->assertExitCode(0);

        $this->assertNotNull($intent->fresh()->completed_at);
        Storage::disk('public')->assertMissing('agotada.webp');
    }

    // ── 9. Borrado de pallet y de base: intenciones para todas las fotos ─

    public function test_borrar_pallet_registra_intenciones_de_fotos_y_fotos_de_bases(): void
    {
        $user = User::factory()->withRole()->create();
        $pallet = Pallet::factory()->create();
        $fotoPallet = $this->fotoDePallet($pallet, "pallets/{$pallet->id}/p1.webp");
        $fotoPallet2 = $this->fotoDePallet($pallet, "pallets/{$pallet->id}/p2.webp");
        $base = PalletBase::factory()->create(['pallet_id' => $pallet->id]);
        $fotoBase = $this->fotoDeBase($base, "pallets/{$pallet->id}/bases/{$base->id}/b1.webp");

        $this->actingAs($user)
            ->delete("/api/v1/pallets/{$pallet->id}")
            ->assertOk();

        $this->assertDatabaseMissing('pallets', ['id' => $pallet->id]);
        $this->assertDatabaseCount('pallet_photos', 0);
        $this->assertDatabaseCount('pallet_base_photos', 0);
        $this->assertDatabaseHas('activity_logs', ['action' => 'pallet_deleted']);

        $this->assertSame(
            collect([$fotoPallet, $fotoPallet2, $fotoBase])->pluck('path')->sort()->values()->all(),
            PendingFileDeletion::pluck('path')->sort()->values()->all(),
        );

        $this->artisan('storage:retry-pending-deletions')->assertExitCode(0);

        foreach ([$fotoPallet, $fotoPallet2, $fotoBase] as $foto) {
            Storage::disk('public')->assertMissing($foto->path);
        }
    }

    public function test_borrar_base_registra_intenciones_de_sus_fotos(): void
    {
        $user = User::factory()->withRole()->create();
        $base = PalletBase::factory()->create();
        $foto1 = $this->fotoDeBase($base, "pallets/{$base->pallet_id}/bases/{$base->id}/b1.webp");
        $foto2 = $this->fotoDeBase($base, "pallets/{$base->pallet_id}/bases/{$base->id}/b2.webp");

        $this->actingAs($user)
            ->delete("/api/v1/pallets/{$base->pallet_id}/bases/{$base->id}")
            ->assertOk();

        $this->assertDatabaseMissing('pallet_bases', ['id' => $base->id]);
        $this->assertDatabaseCount('pallet_base_photos', 0);
        $this->assertDatabaseHas('activity_logs', ['action' => 'base_deleted']);

        $this->assertSame(
            collect([$foto1, $foto2])->pluck('path')->sort()->values()->all(),
            PendingFileDeletion::pluck('path')->sort()->values()->all(),
        );

        $this->artisan('storage:retry-pending-deletions')->assertExitCode(0);

        Storage::disk('public')->assertMissing($foto1->path);
        Storage::disk('public')->assertMissing($foto2->path);
    }

    // ── Helpers ──────────────────────────────────────────────────────────

    private function fotoDePallet(Pallet $pallet, string $path): PalletPhoto
    {
        Storage::disk('public')->put($path, 'bytes');

        return PalletPhoto::create([
            'pallet_id' => $pallet->id,
            'path' => $path,
            'original_name' => basename($path),
        ]);
    }

    private function fotoDeBase(PalletBase $base, string $path): PalletBasePhoto
    {
        Storage::disk('public')->put($path, 'bytes');

        return PalletBasePhoto::create([
            'base_id' => $base->id,
            'path' => $path,
            'original_name' => basename($path),
        ]);
    }

    private function fotoDeTicket(OrderTicket $ticket, string $path): OrderTicketPhoto
    {
        Storage::disk('public')->put($path, 'bytes');

        return OrderTicketPhoto::create([
            'ticket_id' => $ticket->id,
            'path' => $path,
            'original_name' => basename($path),
        ]);
    }

    private function failActivityLogs(): void
    {
        DB::statement(<<<'SQL'
            CREATE TRIGGER fail_activity_log
            BEFORE INSERT ON activity_logs
            BEGIN
                SELECT RAISE(ABORT, 'forced audit failure');
            END
        SQL);
    }
}
