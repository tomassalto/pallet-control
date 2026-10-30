<?php

namespace Tests\Feature\Order;

use App\Models\Order;
use App\Models\OrderItem;
use App\Models\PalletBase;
use App\Models\Product;
use App\Models\User;
use Illuminate\Database\QueryException;
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

    public function test_la_base_de_datos_rechaza_ean_duplicado_en_el_mismo_pedido(): void
    {
        $order = Order::factory()->create();
        OrderItem::factory()->for($order)->create(['ean' => '7791234567890']);

        $this->expectException(QueryException::class);
        OrderItem::factory()->for($order)->create(['ean' => '7791234567890']);
    }

    public function test_el_mismo_ean_puede_existir_en_pedidos_distintos(): void
    {
        $firstOrder = Order::factory()->create();
        $secondOrder = Order::factory()->create();

        OrderItem::factory()->for($firstOrder)->create(['ean' => '7791234567890']);
        OrderItem::factory()->for($secondOrder)->create(['ean' => '7791234567890']);

        $this->assertDatabaseCount('order_items', 2);
    }

    public function test_agregar_item_por_ultimos_cuatro_resuelve_el_producto(): void
    {
        $order = Order::factory()->create();
        Product::create([
            'ean' => '7791234567890',
            'ean_last4' => '7890',
            'name' => 'Producto conocido',
        ]);

        $this->actingAs($this->actingUser())
            ->postJson("/api/v1/orders/{$order->id}/items", [
                'last4' => '7890',
                'qty' => 3,
            ])
            ->assertCreated()
            ->assertJsonPath('ean', '7791234567890')
            ->assertJsonPath('description', 'Producto conocido');
    }

    public function test_agregar_item_rechaza_ultimos_cuatro_no_numericos(): void
    {
        $order = Order::factory()->create();

        $this->actingAs($this->actingUser())
            ->postJson("/api/v1/orders/{$order->id}/items", [
                'last4' => 'ABCD',
                'qty' => 3,
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('last4');
    }

    public function test_agregar_item_revierte_el_alta_si_falla_el_audit_log(): void
    {
        $order = Order::factory()->create();
        DB::statement(<<<'SQL'
            CREATE TRIGGER fail_activity_log
            BEFORE INSERT ON activity_logs
            BEGIN
                SELECT RAISE(ABORT, 'forced audit failure');
            END
        SQL);

        $this->actingAs($this->actingUser())
            ->postJson("/api/v1/orders/{$order->id}/items", [
                'ean' => '7791234567890',
                'qty' => 3,
            ])
            ->assertStatus(500);

        $this->assertDatabaseCount('order_items', 0);
    }

    public function test_agregar_item_mantiene_un_numero_acotado_de_queries_con_pedido_grande(): void
    {
        $order = Order::factory()->create();
        OrderItem::factory()->for($order)->count(20)->create();

        $this->actingAs($this->actingUser());
        DB::flushQueryLog();
        DB::enableQueryLog();

        $this->postJson("/api/v1/orders/{$order->id}/items", [
            'ean' => '7791234567890',
            'qty' => 3,
        ])->assertCreated();

        $queryCount = count(DB::getQueryLog());
        DB::disableQueryLog();

        // 8 = 7 + el FOR SHARE del pallet que exige la orden de locks D-020.
        $this->assertSame(8, $queryCount, "El alta ejecutó {$queryCount} queries.");
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

    public function test_actualizar_item_rechaza_cantidad_completada_mayor_que_la_pedida(): void
    {
        $user = $this->actingUser();
        $item = OrderItem::factory()->create(['qty' => 10, 'done_qty' => 4]);

        $this->actingAs($user)
            ->patchJson("/api/v1/order-items/{$item->id}", ['done_qty' => 11])
            ->assertStatus(409)
            ->assertJsonPath('qty', 10)
            ->assertJsonPath('done_qty', 11);

        $this->assertDatabaseHas('order_items', [
            'id' => $item->id,
            'qty' => 10,
            'done_qty' => 4,
        ]);
    }

    public function test_actualizar_item_rechaza_reducir_qty_por_debajo_de_lo_completado(): void
    {
        $user = $this->actingUser();
        $item = OrderItem::factory()->create(['qty' => 10, 'done_qty' => 6]);

        $this->actingAs($user)
            ->patchJson("/api/v1/order-items/{$item->id}", ['qty' => 5])
            ->assertStatus(409)
            ->assertJsonPath('qty', 5)
            ->assertJsonPath('done_qty', 6);

        $this->assertDatabaseHas('order_items', [
            'id' => $item->id,
            'qty' => 10,
            'done_qty' => 6,
        ]);
    }

    public function test_actualizar_item_inexistente_devuelve_404(): void
    {
        $this->actingAs($this->actingUser())
            ->patchJson('/api/v1/order-items/999999', ['qty' => 2])
            ->assertNotFound();
    }

    public function test_actualizar_item_sin_autenticacion_devuelve_401(): void
    {
        $item = OrderItem::factory()->create();

        $this->patchJson("/api/v1/order-items/{$item->id}", ['qty' => 2])
            ->assertUnauthorized();
    }

    public function test_reducir_qty_por_debajo_de_lo_asignado_devuelve_conflicto(): void
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
            ->assertConflict()
            ->assertJsonPath('assigned', 8)
            ->assertJsonPath('requested', 4);

        $this->assertDatabaseHas('order_items', ['id' => $item->id, 'qty' => 10]);
        $this->assertDatabaseHas('pallet_base_order_items', [
            'order_item_id' => $item->id,
            'qty' => 8,
        ]);
    }

    public function test_reducir_qty_al_total_asignado_esta_permitido(): void
    {
        $item = OrderItem::factory()->create(['qty' => 10]);
        $base = PalletBase::factory()->create();
        $base->orderItems()->attach($item->id, ['qty' => 8]);

        $this->actingAs($this->actingUser())
            ->patchJson("/api/v1/order-items/{$item->id}", ['qty' => 8])
            ->assertOk()
            ->assertJsonPath('qty', 8);
    }

    public function test_actualizar_item_revierte_el_cambio_si_falla_el_audit_log(): void
    {
        $item = OrderItem::factory()->create(['qty' => 10]);
        DB::statement(<<<'SQL'
            CREATE TRIGGER fail_activity_log
            BEFORE INSERT ON activity_logs
            BEGIN
                SELECT RAISE(ABORT, 'forced audit failure');
            END
        SQL);

        $this->actingAs($this->actingUser())
            ->patchJson("/api/v1/order-items/{$item->id}", ['qty' => 12])
            ->assertStatus(500);

        $this->assertDatabaseHas('order_items', ['id' => $item->id, 'qty' => 10]);
    }

    public function test_actualizar_item_mantiene_queries_acotadas_con_muchas_asignaciones(): void
    {
        $item = OrderItem::factory()->create(['qty' => 30]);
        PalletBase::factory()->count(20)->create()->each(
            fn (PalletBase $base) => $base->orderItems()->attach($item->id, ['qty' => 1])
        );

        $this->actingAs($this->actingUser());
        DB::flushQueryLog();
        DB::enableQueryLog();

        $this->patchJson("/api/v1/order-items/{$item->id}", ['qty' => 20])->assertOk();

        $queryCount = count(DB::getQueryLog());
        DB::disableQueryLog();

        // 8 = 6 + lookup de pallets del pedido y FOR SHARE de pallet/order
        // que exige la orden de locks D-020.
        $this->assertSame(8, $queryCount, "La actualización ejecutó {$queryCount} queries.");
    }
}
