<?php

namespace Tests\Feature\Order;

use App\Models\Order;
use App\Models\OrderItem;
use App\Models\PalletBase;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Caracterización previa a R-04 y R-05.
 *
 * Congela el comportamiento actual de los dos endpoints de escritura de ítems
 * de pedido. Los tests marcados como comportamiento incorrecto no corrigen el
 * defecto: lo hacen visible antes de aplicar BACKEND-BIBLIA §2, §5.6 y §6.
 */
class OrderItemCharacterizationTest extends TestCase
{
    use RefreshDatabase;

    private function actingUser(): User
    {
        return User::factory()->withRole()->create();
    }

    public function test_agregar_item_con_ean_crea_el_item(): void
    {
        $order = Order::factory()->create();

        $response = $this->actingAs($this->actingUser())
            ->postJson("/api/v1/orders/{$order->id}/items", [
                'ean' => '7791234567890',
                'qty' => 12,
            ]);

        $response->assertCreated()
            ->assertJsonPath('ean', '7791234567890')
            ->assertJsonPath('qty', 12)
            ->assertJsonPath('description', 'SIN DESCRIPCIÓN');

        $this->assertDatabaseHas('order_items', [
            'order_id' => $order->id,
            'ean' => '7791234567890',
            'qty' => 12,
        ]);
    }

    public function test_agregar_item_requiere_ean_o_last4(): void
    {
        $order = Order::factory()->create();

        $this->actingAs($this->actingUser())
            ->postJson("/api/v1/orders/{$order->id}/items", ['qty' => 1])
            ->assertStatus(422)
            ->assertJsonPath('message', 'Debés enviar ean o last4.');

        $this->assertDatabaseCount('order_items', 0);
    }

    public function test_agregar_item_rechaza_cantidad_cero(): void
    {
        $order = Order::factory()->create();

        $this->actingAs($this->actingUser())
            ->postJson("/api/v1/orders/{$order->id}/items", [
                'ean' => '7791234567890',
                'qty' => 0,
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('qty');
    }

    public function test_agregar_ean_duplicado_devuelve_conflicto_sin_crear_otro_item(): void
    {
        $order = Order::factory()->create();
        OrderItem::factory()->for($order)->create(['ean' => '7791234567890']);

        $this->actingAs($this->actingUser())
            ->postJson("/api/v1/orders/{$order->id}/items", [
                'ean' => '7791234567890',
                'qty' => 3,
            ])
            ->assertConflict()
            ->assertJsonPath('message', 'Este producto ya está en el pedido.');

        $this->assertDatabaseCount('order_items', 1);
    }

    public function test_agregar_item_sin_autenticacion_devuelve_401(): void
    {
        $order = Order::factory()->create();

        $this->postJson("/api/v1/orders/{$order->id}/items", [
            'ean' => '7791234567890',
            'qty' => 1,
        ])->assertUnauthorized();
    }

    public function test_actualizar_item_modifica_cantidad_estado_y_cantidad_completada(): void
    {
        $item = OrderItem::factory()->create([
            'qty' => 10,
            'status' => 'pending',
            'done_qty' => 0,
        ]);

        $this->actingAs($this->actingUser())
            ->patchJson("/api/v1/order-items/{$item->id}", [
                'qty' => 14,
                'status' => 'done',
                'done_qty' => 14,
            ])
            ->assertOk()
            ->assertJsonPath('qty', 14)
            ->assertJsonPath('status', 'done')
            ->assertJsonPath('done_qty', 14);
    }

    public function test_actualizar_item_rechaza_estado_invalido_y_cantidad_negativa(): void
    {
        $item = OrderItem::factory()->create();

        $this->actingAs($this->actingUser())
            ->patchJson("/api/v1/order-items/{$item->id}", [
                'qty' => -1,
                'status' => 'cancelled',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['qty', 'status']);
    }

    public function test_actualizar_item_inexistente_devuelve_404(): void
    {
        $this->actingAs($this->actingUser())
            ->patchJson('/api/v1/order-items/999999', ['qty' => 2])
            ->assertNotFound();
    }

    /**
     * COMPORTAMIENTO ACTUAL INCORRECTO — ver AUDITORIA.md H-010.
     * El endpoint permite bajar qty por debajo de las unidades ya asignadas.
     */
    public function test_bug_reducir_qty_por_debajo_de_lo_asignado_es_aceptado(): void
    {
        $item = OrderItem::factory()->create(['qty' => 10]);
        $base = PalletBase::factory()->create();
        DB::table('pallet_base_order_items')->insert([
            'base_id' => $base->id,
            'order_item_id' => $item->id,
            'qty' => 8,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->actingAs($this->actingUser())
            ->patchJson("/api/v1/order-items/{$item->id}", ['qty' => 4])
            ->assertOk()
            ->assertJsonPath('qty', 4);

        $this->assertDatabaseHas('order_items', ['id' => $item->id, 'qty' => 4]);
        $this->assertDatabaseHas('pallet_base_order_items', [
            'order_item_id' => $item->id,
            'qty' => 8,
        ]);
    }
}
