<?php

namespace Tests\Feature\Contract;

use App\Models\MissingItemRequest;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Pallet;
use App\Models\PalletBase;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * D-025: los conflictos de estado responden 409; las entradas
 * semánticamente inválidas siguen en 422.
 */
class StatusConsistencyTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['filesystems.default' => 'public']);
        Storage::fake('public');
    }

    public function test_finalizar_pallet_sin_requisitos_devuelve_409_con_detalles_y_sin_cambios(): void
    {
        $user = User::factory()->withRole()->create();
        $pallet = Pallet::factory()->create(['status' => 'open']);
        PalletBase::factory()->for($pallet, 'pallet')->create();

        $response = $this->actingAs($user)
            ->postJson("/api/v1/pallets/{$pallet->id}/finalize");

        $response->assertStatus(409)
            ->assertJsonPath('message', 'Se requieren al menos 2 bases, 2 fotos en total, y todas las bases deben tener al menos 1 producto asignado.')
            ->assertJsonStructure([
                'message', 'bases_count', 'total_photos',
                'bases_with_products', 'all_bases_have_products', 'requirements',
            ]);
        $this->assertSame('open', $pallet->fresh()->status);
        $this->assertDatabaseMissing('activity_logs', [
            'action' => 'pallet_finalized', 'entity_id' => $pallet->id,
        ]);
    }

    public function test_reabrir_pallet_no_finalizado_devuelve_409_y_sin_cambios(): void
    {
        $user = User::factory()->withRole()->create();
        $pallet = Pallet::factory()->create(['status' => 'open']);

        $response = $this->actingAs($user)
            ->postJson("/api/v1/pallets/{$pallet->id}/reopen");

        $response->assertStatus(409)
            ->assertJsonPath('message', 'Solo se pueden reabrir pallets que estén finalizados');
        $this->assertSame('open', $pallet->fresh()->status);
        $this->assertDatabaseMissing('activity_logs', [
            'action' => 'pallet_reopened', 'entity_id' => $pallet->id,
        ]);
    }

    public function test_finalizar_pedido_sin_pallets_devuelve_409_y_sin_cambios(): void
    {
        $user = User::factory()->withRole()->create();
        $order = Order::factory()->create();
        OrderItem::factory()->for($order)->create();

        $response = $this->actingAs($user)
            ->postJson("/api/v1/orders/{$order->id}/finalize");

        $response->assertStatus(409)
            ->assertJsonPath('message', 'El pedido debe tener al menos 1 pallet asociado para finalizar');
        $this->assertNotSame('done', $order->fresh()->status);
        $this->assertDatabaseMissing('activity_logs', [
            'action' => 'order_finalized', 'entity_id' => $order->id,
        ]);
    }

    public function test_adjust_item_en_pallet_finalizado_devuelve_409_y_sin_cambios(): void
    {
        $user = User::factory()->withRole()->create();
        $pallet = Pallet::factory()->create(['status' => 'done']);
        $base = PalletBase::factory()->for($pallet, 'pallet')->create();
        $order = Order::factory()->create();
        $item = OrderItem::factory()->for($order)->create(['qty' => 10]);

        $response = $this->actingAs($user)
            ->patchJson("/api/v1/pallets/{$pallet->id}/bases/{$base->id}/adjust-item", [
                'order_item_id' => $item->id,
                'qty' => 5,
            ]);

        $response->assertStatus(409)
            ->assertJsonPath('message', 'El pallet está finalizado. Reabrilo antes de modificar sus bases.');
        $this->assertDatabaseCount('pallet_base_order_items', 0);
        $this->assertDatabaseMissing('activity_logs', [
            'action' => 'item_qty_adjusted', 'entity_id' => $item->id,
        ]);
    }

    public function test_solicitud_duplicada_devuelve_409_y_no_crea_fila(): void
    {
        $order = Order::factory()->create();
        $item = OrderItem::factory()->for($order)->create(['qty' => 10]);
        MissingItemRequest::create([
            'order_id' => $order->id,
            'order_item_id' => $item->id,
            'qty_missing' => 1,
            'status' => 'pending_review',
        ]);

        $response = $this->postJson(
            "/api/v1/public/orders/{$order->public_token}/missing-items",
            ['order_item_id' => $item->id, 'qty_missing' => 1],
        );

        $response->assertStatus(409)
            ->assertJsonPath('message', 'Ya existe una solicitud reciente para este producto');
        $this->assertSame(1, MissingItemRequest::count());
    }

    // ── Regresión: lo que sigue siendo 422 ────────────────────────────────

    public function test_solicitud_con_cantidad_mayor_al_pedido_sigue_422(): void
    {
        $order = Order::factory()->create();
        $item = OrderItem::factory()->for($order)->create(['qty' => 10]);

        $response = $this->postJson(
            "/api/v1/public/orders/{$order->public_token}/missing-items",
            ['order_item_id' => $item->id, 'qty_missing' => 11],
        );

        $response->assertStatus(422)
            ->assertJsonPath('message', 'La cantidad no puede superar la del pedido');
        $this->assertSame(0, MissingItemRequest::count());
    }
}
