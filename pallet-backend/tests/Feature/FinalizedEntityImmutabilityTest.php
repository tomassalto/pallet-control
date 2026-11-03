<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\MissingItemRequest;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OrderTicket;
use App\Models\OrderTicketPhoto;
use App\Models\Pallet;
use App\Models\PalletBase;
use App\Models\PalletBasePhoto;
use App\Models\PalletPhoto;
use App\Models\PendingItem;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Un pedido o pallet `done` es inmutable: ningún escritor confirma
 * cambios sin una reapertura auditada anterior (D-031). El PATCH
 * genérico no puede finalizar ni reabrir (H-046).
 */
class FinalizedEntityImmutabilityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['filesystems.default' => 'public']);
        Storage::fake('public');
        config([
            'services.telegram.token' => 'test-token',
            'services.telegram.chat_id' => 'test-chat',
        ]);
        Http::fake();
    }

    private function actingUser(): User
    {
        return User::factory()->withRole()->create();
    }

    private function adminUser(): User
    {
        return User::factory()->withRole('admin')->create();
    }

    private function palletConBase(string $status = 'done'): array
    {
        $pallet = Pallet::factory()->create(['status' => $status]);
        $order = Order::factory()->create();
        $pallet->orders()->attach($order->id);
        $item = OrderItem::factory()->for($order)->create(['qty' => 10]);
        $base = PalletBase::factory()->for($pallet, 'pallet')->create();
        $base->orderItems()->attach($item->id, ['qty' => 5]);

        return [$pallet, $order, $item, $base];
    }

    private function orderConItem(string $status = 'done'): array
    {
        $order = Order::factory()->create(['status' => $status]);
        $item = OrderItem::factory()->for($order)->create(['qty' => 10]);

        return [$order, $item];
    }

    // ───────────────────────────────────────────────────────────
    // PATCH genérico (H-046): no finaliza, no reabre, sí pausa
    // ───────────────────────────────────────────────────────────

    public function test_patch_pallet_no_puede_finalizar(): void
    {
        $pallet = Pallet::factory()->create(['status' => 'open']);

        $this->actingAs($this->actingUser())
            ->patchJson("/api/v1/pallets/{$pallet->id}", ['status' => 'done'])
            ->assertConflict()
            ->assertJsonPath('message', 'Para finalizar el pallet usá el endpoint de finalización.');

        $this->assertSame('open', $pallet->fresh()->status);
        $this->assertDatabaseMissing('activity_logs', ['action' => 'pallet_status_changed']);
    }

    public function test_patch_pedido_no_puede_finalizar(): void
    {
        $order = Order::factory()->create(['status' => 'open']);

        $this->actingAs($this->actingUser())
            ->patchJson("/api/v1/orders/{$order->id}", ['status' => 'done'])
            ->assertConflict()
            ->assertJsonPath('message', 'Para finalizar el pedido usá el endpoint de finalización.');

        $this->assertSame('open', $order->fresh()->status);
    }

    public function test_patch_pallet_finalizado_rechaza_estado_y_nota(): void
    {
        $pallet = Pallet::factory()->create(['status' => 'done', 'note' => 'vieja']);

        $this->actingAs($this->actingUser())
            ->patchJson("/api/v1/pallets/{$pallet->id}", ['status' => 'open', 'note' => 'nueva'])
            ->assertConflict()
            ->assertJsonPath('message', 'El pallet está finalizado. Reabrilo antes de modificarlo.');

        $fresh = $pallet->fresh();
        $this->assertSame('done', $fresh->status);
        $this->assertSame('vieja', $fresh->note);
    }

    public function test_patch_pedido_finalizado_rechaza_estado_y_nota(): void
    {
        $order = Order::factory()->create(['status' => 'done', 'note' => 'vieja']);

        $this->actingAs($this->actingUser())
            ->patchJson("/api/v1/orders/{$order->id}", ['status' => 'paused', 'note' => 'nueva'])
            ->assertConflict()
            ->assertJsonPath('message', 'El pedido está finalizado. Reabrilo antes de modificarlo.');

        $fresh = $order->fresh();
        $this->assertSame('done', $fresh->status);
        $this->assertSame('vieja', $fresh->note);
    }

    public function test_patch_pausa_y_reanudacion_siguen_funcionando_con_auditoria(): void
    {
        $pallet = Pallet::factory()->create(['status' => 'open']);
        $user = $this->actingUser();

        $this->actingAs($user)
            ->patchJson("/api/v1/pallets/{$pallet->id}", ['status' => 'paused'])
            ->assertOk()
            ->assertJsonPath('status', 'paused');

        $this->actingAs($user)
            ->patchJson("/api/v1/pallets/{$pallet->id}", ['status' => 'open'])
            ->assertOk();

        $logs = ActivityLog::where('action', 'pallet_status_changed')->orderBy('id')->get();
        $this->assertCount(2, $logs);
        $this->assertSame('open', $logs[0]->old_values['status']);
        $this->assertSame('paused', $logs[0]->new_values['status']);
        $this->assertSame('paused', $logs[1]->old_values['status']);
        $this->assertSame('open', $logs[1]->new_values['status']);
    }

    public function test_patch_pausa_pedido_registra_auditoria(): void
    {
        $order = Order::factory()->create(['status' => 'open']);

        $this->actingAs($this->actingUser())
            ->patchJson("/api/v1/orders/{$order->id}", ['status' => 'paused'])
            ->assertOk()
            ->assertJsonPath('status', 'paused');

        $log = ActivityLog::where('action', 'order_status_changed')->sole();
        $this->assertSame('open', $log->old_values['status']);
        $this->assertSame('paused', $log->new_values['status']);
        $this->assertSame($order->id, $log->order_id);
    }

    // ───────────────────────────────────────────────────────────
    // Reapertura de pedido (autoridad transaccional)
    // ───────────────────────────────────────────────────────────

    public function test_reabrir_pedido_confirma_estado_auditoria_y_telegram(): void
    {
        [$order] = $this->orderConItem('done');

        $this->actingAs($this->actingUser())
            ->postJson("/api/v1/orders/{$order->id}/reopen")
            ->assertOk()
            ->assertJsonPath('order.status', 'open');

        $this->assertSame('open', $order->fresh()->status);

        $log = ActivityLog::where('action', 'order_reopened')->sole();
        $this->assertSame('done', $log->old_values['status']);
        $this->assertSame('open', $log->new_values['status']);
        $this->assertSame($order->id, $log->order_id);

        Http::assertSentCount(1);
        Http::assertSent(fn ($request) => str_contains($request->body(), 'reabierto'));
    }

    public function test_reabrir_pedido_abierto_409_sin_log_ni_telegram(): void
    {
        $order = Order::factory()->create(['status' => 'open']);

        $this->actingAs($this->actingUser())
            ->postJson("/api/v1/orders/{$order->id}/reopen")
            ->assertConflict()
            ->assertJsonPath('message', 'Solo se pueden reabrir pedidos que estén finalizados');

        $this->assertDatabaseMissing('activity_logs', ['action' => 'order_reopened']);
        Http::assertNothingSent();
    }

    // ───────────────────────────────────────────────────────────
    // Pallet done: bases, fotos, anotaciones, attach/detach, delete
    // ───────────────────────────────────────────────────────────

    public function test_pallet_finalizado_rechaza_crear_base(): void
    {
        [$pallet] = $this->palletConBase('done');

        $this->actingAs($this->actingUser())
            ->postJson("/api/v1/pallets/{$pallet->id}/bases", ['name' => 'Nueva'])
            ->assertConflict()
            ->assertJsonPath('message', 'El pallet está finalizado. Reabrilo antes de modificarlo.');

        $this->assertSame(1, $pallet->bases()->count());
    }

    public function test_pallet_finalizado_rechaza_actualizar_base(): void
    {
        [$pallet, , , $base] = $this->palletConBase('done');

        $this->actingAs($this->actingUser())
            ->patchJson("/api/v1/pallets/{$pallet->id}/bases/{$base->id}", ['name' => 'Cambiada'])
            ->assertConflict();

        $this->assertNotSame('Cambiada', $base->fresh()->name);
    }

    public function test_pallet_finalizado_rechaza_eliminar_base(): void
    {
        [$pallet, , , $base] = $this->palletConBase('done');

        $this->actingAs($this->actingUser())
            ->deleteJson("/api/v1/pallets/{$pallet->id}/bases/{$base->id}")
            ->assertConflict();

        $this->assertNotNull($base->fresh());
    }

    public function test_pallet_finalizado_rechaza_adjust_item(): void
    {
        [$pallet, , $item, $base] = $this->palletConBase('done');

        $this->actingAs($this->actingUser())
            ->patchJson("/api/v1/pallets/{$pallet->id}/bases/{$base->id}/adjust-item", [
                'order_item_id' => $item->id,
                'qty' => 0,
            ])
            ->assertConflict();

        $this->assertSame(5, (int) $base->orderItems()->first()->pivot->qty);
    }

    public function test_pallet_finalizado_rechaza_migrar(): void
    {
        [$pallet, , $item, $base] = $this->palletConBase('done');
        $destino = Pallet::factory()->create(['status' => 'open']);

        $this->actingAs($this->actingUser())
            ->postJson("/api/v1/pallets/{$pallet->id}/bases/{$base->id}/migrate", [
                'destination_pallet_id' => $destino->id,
                'items' => [['order_item_id' => $item->id, 'qty' => 1]],
            ])
            ->assertConflict();
    }

    public function test_migrar_rechaza_items_de_pedido_finalizado(): void
    {
        [$order, $item] = $this->orderConItem('done');
        $palletOrigen = Pallet::factory()->create(['status' => 'open']);
        $order->pallets()->attach($palletOrigen->id);
        $base = PalletBase::factory()->for($palletOrigen, 'pallet')->create();
        $base->orderItems()->attach($item->id, ['qty' => 5]);
        $destino = Pallet::factory()->create(['status' => 'open']);

        $this->actingAs($this->actingUser())
            ->postJson("/api/v1/pallets/{$palletOrigen->id}/bases/{$base->id}/migrate", [
                'destination_pallet_id' => $destino->id,
                'items' => [['order_item_id' => $item->id, 'qty' => 1]],
            ])
            ->assertConflict()
            ->assertJsonPath('message', 'El pedido está finalizado. Reabrilo antes de migrar sus productos.');
    }

    public function test_adjust_item_rechaza_item_de_pedido_finalizado(): void
    {
        [$order, $item] = $this->orderConItem('done');
        $pallet = Pallet::factory()->create(['status' => 'open']);
        $order->pallets()->attach($pallet->id);
        $base = PalletBase::factory()->for($pallet, 'pallet')->create();
        $base->orderItems()->attach($item->id, ['qty' => 5]);

        $this->actingAs($this->actingUser())
            ->patchJson("/api/v1/pallets/{$pallet->id}/bases/{$base->id}/adjust-item", [
                'order_item_id' => $item->id,
                'qty' => 3,
            ])
            ->assertConflict()
            ->assertJsonPath('message', 'El pedido está finalizado. Reabrilo antes de modificarlo.');
    }

    public function test_pallet_finalizado_rechaza_subir_foto_y_no_deja_archivo(): void
    {
        [$pallet] = $this->palletConBase('done');

        $this->actingAs($this->actingUser())
            ->postJson("/api/v1/pallets/{$pallet->id}/photos", [
                'photo' => UploadedFile::fake()->image('foto.jpg'),
            ])
            ->assertConflict()
            ->assertJsonPath('message', 'El pallet está finalizado. Reabrilo antes de modificarlo.');

        $this->assertSame(0, $pallet->photos()->count());
        $this->assertCount(0, Storage::disk('public')->allFiles("pallets/{$pallet->id}"));
    }

    public function test_pallet_finalizado_rechaza_borrar_foto(): void
    {
        [$pallet] = $this->palletConBase('done');
        $photo = PalletPhoto::create([
            'pallet_id' => $pallet->id,
            'path' => "pallets/{$pallet->id}/foto.webp",
            'original_name' => 'foto.webp',
        ]);

        $this->actingAs($this->actingUser())
            ->deleteJson("/api/v1/pallets/{$pallet->id}/photos/{$photo->id}")
            ->assertConflict();

        $this->assertNotNull($photo->fresh());
    }

    public function test_pallet_finalizado_rechaza_subir_foto_de_base(): void
    {
        [$pallet, , , $base] = $this->palletConBase('done');

        $this->actingAs($this->actingUser())
            ->postJson("/api/v1/pallets/{$pallet->id}/bases/{$base->id}/photos", [
                'photo' => UploadedFile::fake()->image('foto.jpg'),
            ])
            ->assertConflict();

        $this->assertSame(0, $base->photos()->count());
        $this->assertCount(0, Storage::disk('public')->allFiles("pallets/{$pallet->id}"));
    }

    public function test_pallet_finalizado_rechaza_borrar_foto_de_base(): void
    {
        [$pallet, , , $base] = $this->palletConBase('done');
        $photo = PalletBasePhoto::create([
            'base_id' => $base->id,
            'path' => "pallets/{$pallet->id}/bases/{$base->id}/foto.webp",
            'original_name' => 'foto.webp',
        ]);

        $this->actingAs($this->actingUser())
            ->deleteJson("/api/v1/pallets/{$pallet->id}/bases/{$base->id}/photos/{$photo->id}")
            ->assertConflict();

        $this->assertNotNull($photo->fresh());
    }

    public function test_pallet_finalizado_rechaza_guardar_anotaciones(): void
    {
        [$pallet, , , $base] = $this->palletConBase('done');
        $photo = PalletBasePhoto::create([
            'base_id' => $base->id,
            'path' => "pallets/{$pallet->id}/bases/{$base->id}/foto.webp",
            'original_name' => 'foto.webp',
        ]);

        $this->actingAs($this->actingUser())
            ->postJson("/api/v1/pallets/{$pallet->id}/bases/{$base->id}/photos/{$photo->id}/annotations", [
                'annotations' => [['text' => 'x', 'x' => 1, 'y' => 1]],
            ])
            ->assertConflict();

        $this->assertSame(0, $photo->annotations()->count());
    }

    public function test_pallet_finalizado_rechaza_attach_order(): void
    {
        $pallet = Pallet::factory()->create(['status' => 'done']);
        $order = Order::factory()->create(['status' => 'open']);

        $this->actingAs($this->actingUser())
            ->postJson("/api/v1/pallets/{$pallet->id}/attach-order", ['order_code' => $order->code])
            ->assertConflict()
            ->assertJsonPath('message', 'El pallet está finalizado. Reabrilo antes de modificarlo.');

        $this->assertFalse($order->pallets()->where('pallets.id', $pallet->id)->exists());
    }

    public function test_attach_rechaza_pedido_finalizado(): void
    {
        $pallet = Pallet::factory()->create(['status' => 'open']);
        [$order] = $this->orderConItem('done');

        $this->actingAs($this->actingUser())
            ->postJson("/api/v1/pallets/{$pallet->id}/attach-order", ['order_code' => $order->code])
            ->assertConflict()
            ->assertJsonPath('message', 'El pedido está finalizado. Reabrilo antes de modificarlo.');
    }

    public function test_detach_rechaza_pedido_finalizado(): void
    {
        [$order] = $this->orderConItem('done');
        $pallet = Pallet::factory()->create(['status' => 'open']);
        $order->pallets()->attach($pallet->id);

        $this->actingAs($this->actingUser())
            ->deleteJson("/api/v1/orders/{$order->id}/detach-pallet/{$pallet->id}")
            ->assertConflict()
            ->assertJsonPath('message', 'El pedido está finalizado. Reabrilo antes de modificarlo.');

        $this->assertTrue($order->pallets()->where('pallets.id', $pallet->id)->exists());
    }

    public function test_detach_rechaza_pallet_finalizado(): void
    {
        $order = Order::factory()->create(['status' => 'open']);
        $pallet = Pallet::factory()->create(['status' => 'done']);
        $order->pallets()->attach($pallet->id);

        $this->actingAs($this->actingUser())
            ->deleteJson("/api/v1/orders/{$order->id}/detach-pallet/{$pallet->id}")
            ->assertConflict()
            ->assertJsonPath('message', 'El pallet está finalizado. Reabrilo antes de modificarlo.');
    }

    public function test_pallet_finalizado_rechaza_eliminar_pallet(): void
    {
        [$pallet] = $this->palletConBase('done');

        $this->actingAs($this->actingUser())
            ->deleteJson("/api/v1/pallets/{$pallet->id}")
            ->assertConflict()
            ->assertJsonPath('message', 'El pallet está finalizado. Reabrilo antes de eliminarlo.');

        $this->assertNotNull($pallet->fresh());
        Http::assertNothingSent();
    }

    // ───────────────────────────────────────────────────────────
    // Pedido done: items, import, tickets, OCR, pendientes, faltantes
    // ───────────────────────────────────────────────────────────

    public function test_pedido_finalizado_rechaza_agregar_item(): void
    {
        [$order] = $this->orderConItem('done');

        $this->actingAs($this->actingUser())
            ->postJson("/api/v1/orders/{$order->id}/items", ['ean' => '7790001112223', 'qty' => 1])
            ->assertConflict()
            ->assertJsonPath('message', 'El pedido está finalizado. Reabrilo antes de modificarlo.');
    }

    public function test_pedido_finalizado_rechaza_actualizar_item(): void
    {
        [$order, $item] = $this->orderConItem('done');

        $this->actingAs($this->actingUser())
            ->patchJson("/api/v1/order-items/{$item->id}", ['qty' => 20])
            ->assertConflict()
            ->assertJsonPath('message', 'El pedido está finalizado. Reabrilo antes de modificarlo.');

        $this->assertSame(10, (int) $item->fresh()->qty);
    }

    public function test_pedido_finalizado_rechaza_import(): void
    {
        [$order] = $this->orderConItem('done');

        $this->actingAs($this->actingUser())
            ->postJson("/api/v1/orders/{$order->id}/import", [
                'raw' => "7790001112223\tProducto\t0\t5",
            ])
            ->assertConflict()
            ->assertJsonPath('message', 'El pedido está finalizado. Reabrilo antes de modificarlo.');
    }

    public function test_pedido_finalizado_rechaza_crear_ticket(): void
    {
        [$order] = $this->orderConItem('done');

        $this->actingAs($this->actingUser())
            ->postJson("/api/v1/orders/{$order->id}/tickets", ['code' => 'T-1'])
            ->assertConflict()
            ->assertJsonPath('message', 'El pedido está finalizado. Reabrilo antes de modificarlo.');

        $this->assertSame(0, $order->tickets()->count());
    }

    public function test_pedido_finalizado_rechaza_subir_foto_de_ticket(): void
    {
        [$order] = $this->orderConItem('done');
        $ticket = OrderTicket::create(['order_id' => $order->id, 'code' => 'T-1']);

        $this->actingAs($this->actingUser())
            ->postJson("/api/v1/orders/{$order->id}/tickets/{$ticket->id}/photos", [
                'photo' => UploadedFile::fake()->image('ticket.jpg'),
            ])
            ->assertConflict();

        $this->assertSame(0, $ticket->photos()->count());
        $this->assertCount(0, Storage::disk('public')->allFiles("orders/{$order->id}"));
    }

    public function test_pedido_finalizado_rechaza_borrar_ticket(): void
    {
        [$order] = $this->orderConItem('done');
        $ticket = OrderTicket::create(['order_id' => $order->id, 'code' => 'T-1']);

        $this->actingAs($this->actingUser())
            ->deleteJson("/api/v1/orders/{$order->id}/tickets/{$ticket->id}")
            ->assertConflict();

        $this->assertNotNull($ticket->fresh());
    }

    public function test_pedido_finalizado_rechaza_borrar_foto_de_ticket(): void
    {
        [$order] = $this->orderConItem('done');
        $ticket = OrderTicket::create(['order_id' => $order->id, 'code' => 'T-1']);
        $photo = OrderTicketPhoto::create([
            'ticket_id' => $ticket->id,
            'path' => "orders/{$order->id}/tickets/{$ticket->id}/foto.webp",
            'original_name' => 'foto.webp',
        ]);

        $this->actingAs($this->actingUser())
            ->deleteJson("/api/v1/orders/{$order->id}/tickets/{$ticket->id}/photos/{$photo->id}")
            ->assertConflict();

        $this->assertNotNull($photo->fresh());
    }

    public function test_pedido_finalizado_rechaza_trigger_ocr(): void
    {
        [$order] = $this->orderConItem('done');
        $ticket = OrderTicket::create(['order_id' => $order->id, 'code' => 'T-1']);
        $photo = OrderTicketPhoto::create([
            'ticket_id' => $ticket->id,
            'path' => "orders/{$order->id}/tickets/{$ticket->id}/foto.webp",
            'original_name' => 'foto.webp',
        ]);

        $this->actingAs($this->actingUser())
            ->postJson("/api/v1/orders/{$order->id}/tickets/{$ticket->id}/photos/{$photo->id}/trigger-ocr")
            ->assertConflict()
            ->assertJsonPath('message', 'El pedido está finalizado. Reabrilo antes de modificarlo.');

        $this->assertNull($photo->fresh()->ocr_log);
    }

    public function test_pedido_finalizado_rechaza_crear_pendiente(): void
    {
        [$order, $item] = $this->orderConItem('done');

        $this->actingAs($this->actingUser())
            ->postJson('/api/v1/pending-items', [
                'order_id' => $order->id,
                'order_item_id' => $item->id,
                'qty_missing' => 1,
            ])
            ->assertConflict()
            ->assertJsonPath('message', 'El pedido está finalizado. Reabrilo antes de modificarlo.');

        $this->assertSame(0, PendingItem::count());
    }

    public function test_pedido_finalizado_rechaza_actualizar_pendiente(): void
    {
        [$order, $item] = $this->orderConItem('done');
        $pending = PendingItem::create([
            'order_id' => $order->id,
            'order_item_id' => $item->id,
            'qty_missing' => 1,
            'status' => 'pending',
            'created_by' => $this->actingUser()->id,
        ]);

        $this->actingAs($this->actingUser())
            ->patchJson("/api/v1/pending-items/{$pending->id}", ['status' => 'resolved'])
            ->assertConflict();

        $this->assertSame('pending', $pending->fresh()->status);
    }

    public function test_pedido_finalizado_rechaza_borrar_pendiente(): void
    {
        [$order, $item] = $this->orderConItem('done');
        $pending = PendingItem::create([
            'order_id' => $order->id,
            'order_item_id' => $item->id,
            'qty_missing' => 1,
            'status' => 'pending',
            'created_by' => $this->actingUser()->id,
        ]);

        $this->actingAs($this->actingUser())
            ->deleteJson("/api/v1/pending-items/{$pending->id}")
            ->assertConflict();

        $this->assertNotNull($pending->fresh());
    }

    public function test_pedido_finalizado_rechaza_solicitud_publica_de_faltante(): void
    {
        [$order, $item] = $this->orderConItem('done');

        $this->postJson("/api/v1/public/orders/{$order->public_token}/missing-items", [
            'order_item_id' => $item->id,
            'qty_missing' => 1,
        ])
            ->assertConflict()
            ->assertJsonPath('message', 'El pedido está finalizado. Reabrilo antes de modificarlo.');

        $this->assertSame(0, MissingItemRequest::count());
    }

    public function test_pedido_finalizado_rechaza_revision_de_faltante(): void
    {
        [$order, $item] = $this->orderConItem('done');
        $request = MissingItemRequest::create([
            'order_id' => $order->id,
            'order_item_id' => $item->id,
            'qty_missing' => 1,
            'status' => 'pending_review',
        ]);

        $this->actingAs($this->adminUser())
            ->patchJson("/api/v1/admin/missing-item-requests/{$request->id}", ['status' => 'approved'])
            ->assertConflict()
            ->assertJsonPath('message', 'El pedido está finalizado. Reabrilo antes de modificarlo.');

        $this->assertSame('pending_review', $request->fresh()->status);
        $this->assertSame(0, PendingItem::count());
    }

    public function test_admin_storage_rechaza_borrar_pallet_y_pedido_finalizados(): void
    {
        $pallet = Pallet::factory()->create(['status' => 'done']);
        [$order] = $this->orderConItem('done');
        $admin = $this->adminUser();

        $this->actingAs($admin)
            ->deleteJson("/api/v1/admin/storage/pallets/{$pallet->id}")
            ->assertConflict();
        $this->actingAs($admin)
            ->deleteJson("/api/v1/admin/storage/orders/{$order->id}")
            ->assertConflict();

        $this->assertNotNull($pallet->fresh());
        $this->assertNotNull($order->fresh());
    }

    // ───────────────────────────────────────────────────────────
    // Reapertura restaura la mutabilidad
    // ───────────────────────────────────────────────────────────

    public function test_despues_de_reabrir_el_pallet_vuelve_a_aceptar_escrituras(): void
    {
        [$pallet] = $this->palletConBase('done');
        $user = $this->actingUser();

        $this->actingAs($user)
            ->postJson("/api/v1/pallets/{$pallet->id}/reopen")
            ->assertOk();

        $this->actingAs($user)
            ->postJson("/api/v1/pallets/{$pallet->id}/bases", ['name' => 'Nueva'])
            ->assertCreated();
    }

    public function test_despues_de_reabrir_el_pedido_vuelve_a_aceptar_escrituras(): void
    {
        [$order] = $this->orderConItem('done');
        $user = $this->actingUser();

        $this->actingAs($user)
            ->postJson("/api/v1/orders/{$order->id}/reopen")
            ->assertOk();

        $this->actingAs($user)
            ->postJson("/api/v1/orders/{$order->id}/tickets", ['code' => 'T-1'])
            ->assertCreated();
    }
}
