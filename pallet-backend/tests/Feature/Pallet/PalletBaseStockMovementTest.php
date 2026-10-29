<?php

namespace Tests\Feature\Pallet;

use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Pallet;
use App\Models\PalletBase;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Tests de caracterización — documentan el comportamiento ACTUAL de los
 * endpoints de "movimiento de stock" entre bases de pallets:
 *
 *   POST   /pallets/{pallet}/bases                    (PalletBaseController::store)
 *   PATCH  /pallets/{pallet}/bases/{base}              (PalletBaseController::update)
 *   PATCH  /pallets/{pallet}/bases/{base}/adjust-item  (PalletBaseController::adjustItem)
 *   POST   /pallets/{pallet}/bases/{base}/migrate      (PalletBaseController::migrate)
 *
 * IMPORTANTE: varios de estos tests documentan bugs conocidos a propósito
 * (ver AUDITORIA.md H-005, H-006, H-006b, H-011). NO se corrige nada acá.
 * Si un refactor hace que alguno de estos asserts falle, comparar contra
 * el hallazgo correspondiente antes de "arreglar el test" — puede ser que
 * el refactor haya corregido el bug, en cuyo caso el test se actualiza
 * a propósito (y se marca H-XXX como resuelto en AUDITORIA.md).
 */
class PalletBaseStockMovementTest extends TestCase
{
    use RefreshDatabase;

    private function actingUser(): User
    {
        return User::factory()->withRole()->create();
    }

    /** Pallet + pedido asociado + item con qty=$qty, listos para asignar a una base. */
    private function palletWithAssignableItem(int $qty = 10): array
    {
        $pallet = Pallet::factory()->create();
        $order = Order::factory()->create();
        $pallet->orders()->attach($order->id);
        $item = OrderItem::factory()->for($order)->create(['qty' => $qty]);

        return [$pallet, $order, $item];
    }

    // ───────────────────────────────────────────────────────────
    // POST /pallets/{pallet}/bases — creación de base + asignación
    // ───────────────────────────────────────────────────────────

    public function test_crear_base_sin_items_es_valido(): void
    {
        $pallet = Pallet::factory()->create();

        $response = $this->actingAs($this->actingUser())
            ->postJson("/api/v1/pallets/{$pallet->id}/bases", ['name' => 'Base A']);

        $response->assertCreated();
        $this->assertDatabaseHas('pallet_bases', ['pallet_id' => $pallet->id, 'name' => 'Base A']);
    }

    public function test_crear_base_con_item_dentro_de_lo_disponible_asigna_la_cantidad(): void
    {
        [$pallet, , $item] = $this->palletWithAssignableItem(qty: 10);

        $response = $this->actingAs($this->actingUser())
            ->postJson("/api/v1/pallets/{$pallet->id}/bases", [
                'items' => [['order_item_id' => $item->id, 'qty' => 6]],
            ]);

        $response->assertCreated();
        $baseId = $response->json('id');
        $this->assertDatabaseHas('pallet_base_order_items', [
            'base_id' => $baseId,
            'order_item_id' => $item->id,
            'qty' => 6,
        ]);
    }

    public function test_crear_base_pidiendo_mas_de_lo_disponible_devuelve_422(): void
    {
        [$pallet, , $item] = $this->palletWithAssignableItem(qty: 10);

        $response = $this->actingAs($this->actingUser())
            ->postJson("/api/v1/pallets/{$pallet->id}/bases", [
                'items' => [['order_item_id' => $item->id, 'qty' => 15]],
            ]);

        $response->assertStatus(422);
        $response->assertJsonPath('available', 10);
        $response->assertJsonPath('requested', 15);
    }

    /**
     * H-011 (AUDITORIA.md): no hay transacción en PalletBaseService::createBase.
     * La PalletBase se crea con PalletBase::create() ANTES de validar los items,
     * y si la validación falla el método hace `return` sin revertirla.
     * Esto documenta que, hoy, un 422 en este endpoint puede dejar una base
     * vacía "huérfana" en la base de datos.
     */
    public function test_bug_base_queda_creada_en_db_aunque_la_asignacion_de_items_falle(): void
    {
        [$pallet, , $item] = $this->palletWithAssignableItem(qty: 10);

        $this->actingAs($this->actingUser())
            ->postJson("/api/v1/pallets/{$pallet->id}/bases", [
                'items' => [['order_item_id' => $item->id, 'qty' => 15]],
            ])
            ->assertStatus(422);

        $this->assertDatabaseCount('pallet_bases', 1);
        $this->assertSame(0, DB::table('pallet_base_order_items')->count());
    }

    /**
     * PalletBaseService::createBase solo agrega el item al sync si
     * `$pallet->orders->contains('id', $orderItem->order_id)`. Si el
     * order_item pertenece a un pedido que NO está asociado al pallet,
     * el item se salta EN SILENCIO — no hay error, y la base sí se crea
     * sin ese producto. Se documenta ese comportamiento actual.
     */
    public function test_item_de_pedido_no_asociado_al_pallet_se_ignora_sin_error(): void
    {
        $pallet = Pallet::factory()->create(); // sin pedidos asociados
        $otherOrder = Order::factory()->create();
        $item = OrderItem::factory()->for($otherOrder)->create(['qty' => 10]);

        $response = $this->actingAs($this->actingUser())
            ->postJson("/api/v1/pallets/{$pallet->id}/bases", [
                'items' => [['order_item_id' => $item->id, 'qty' => 5]],
            ]);

        $response->assertCreated(); // no falla
        $this->assertSame(0, DB::table('pallet_base_order_items')->count()); // pero no asigna nada
    }

    public function test_crear_base_bloqueada_si_el_pedido_del_item_esta_finalizado(): void
    {
        [$pallet, $order, $item] = $this->palletWithAssignableItem(qty: 10);
        $order->update(['status' => 'done']);

        $response = $this->actingAs($this->actingUser())
            ->postJson("/api/v1/pallets/{$pallet->id}/bases", [
                'items' => [['order_item_id' => $item->id, 'qty' => 5]],
            ]);

        $response->assertStatus(422);
        $response->assertJsonFragment(['order_code' => $order->code]);
    }

    // ───────────────────────────────────────────────────────────
    // PATCH /pallets/{pallet}/bases/{base} — reasignación de items
    // ───────────────────────────────────────────────────────────

    public function test_actualizar_base_de_otro_pallet_devuelve_404(): void
    {
        $pallet = Pallet::factory()->create();
        $otherPallet = Pallet::factory()->create();
        $base = PalletBase::factory()->for($otherPallet, 'pallet')->create();

        $response = $this->actingAs($this->actingUser())
            ->patchJson("/api/v1/pallets/{$pallet->id}/bases/{$base->id}", ['name' => 'x']);

        $response->assertStatus(404);
    }

    public function test_actualizar_nombre_y_nota_de_la_base(): void
    {
        $pallet = Pallet::factory()->create();
        $base = PalletBase::factory()->for($pallet, 'pallet')->create(['name' => 'Base vieja']);

        $response = $this->actingAs($this->actingUser())
            ->patchJson("/api/v1/pallets/{$pallet->id}/bases/{$base->id}", [
                'name' => 'Base nueva',
                'note' => 'nota actualizada',
            ]);

        $response->assertOk();
        $this->assertDatabaseHas('pallet_bases', ['id' => $base->id, 'name' => 'Base nueva']);
    }

    /**
     * H-011: PalletBaseService::updateBase llama a $base->update([name/note])
     * ANTES de validar los items via syncBaseItems(). Si la validación de
     * cantidad falla, el nombre/nota YA quedaron guardados — solo el 422
     * se devuelve, sin revertir el update anterior. Se documenta a propósito.
     */
    public function test_bug_nombre_se_actualiza_aunque_la_reasignacion_de_items_falle(): void
    {
        [$pallet, , $item] = $this->palletWithAssignableItem(qty: 10);
        $base = PalletBase::factory()->for($pallet, 'pallet')->create(['name' => 'Base original']);

        $response = $this->actingAs($this->actingUser())
            ->patchJson("/api/v1/pallets/{$pallet->id}/bases/{$base->id}", [
                'name' => 'Base con overbooking',
                'items' => [['order_item_id' => $item->id, 'qty' => 999]],
            ]);

        $response->assertStatus(422);
        // El nombre SÍ cambió a pesar del 422 — comportamiento actual, no deseado.
        $this->assertDatabaseHas('pallet_bases', ['id' => $base->id, 'name' => 'Base con overbooking']);
    }

    // ───────────────────────────────────────────────────────────
    // PATCH /pallets/{pallet}/bases/{base}/adjust-item
    // ───────────────────────────────────────────────────────────

    public function test_adjust_item_requiere_autenticacion(): void
    {
        [$pallet, , $item] = $this->palletWithAssignableItem(qty: 10);
        $base = PalletBase::factory()->for($pallet, 'pallet')->create();

        $this->patchJson("/api/v1/pallets/{$pallet->id}/bases/{$base->id}/adjust-item", [
            'order_item_id' => $item->id,
            'qty' => 4,
        ])->assertUnauthorized();
    }

    public function test_adjust_item_en_base_de_otro_pallet_devuelve_404(): void
    {
        $pallet = Pallet::factory()->create();
        $otherPallet = Pallet::factory()->create();
        $base = PalletBase::factory()->for($otherPallet, 'pallet')->create();
        $item = OrderItem::factory()->create(['qty' => 10]);

        $response = $this->actingAs($this->actingUser())
            ->patchJson("/api/v1/pallets/{$pallet->id}/bases/{$base->id}/adjust-item", [
                'order_item_id' => $item->id,
                'qty' => 5,
            ]);

        $response->assertStatus(404);
    }

    public function test_adjust_item_bloqueado_si_el_pallet_esta_finalizado(): void
    {
        $pallet = Pallet::factory()->create(['status' => 'done']);
        $base = PalletBase::factory()->for($pallet, 'pallet')->create();
        $item = OrderItem::factory()->create(['qty' => 10]);

        $response = $this->actingAs($this->actingUser())
            ->patchJson("/api/v1/pallets/{$pallet->id}/bases/{$base->id}/adjust-item", [
                'order_item_id' => $item->id,
                'qty' => 5,
            ]);

        $response->assertStatus(422);
    }

    public function test_adjust_item_crea_la_asignacion_si_no_existia(): void
    {
        [$pallet, , $item] = $this->palletWithAssignableItem(qty: 10);
        $base = PalletBase::factory()->for($pallet, 'pallet')->create();
        $user = $this->actingUser();

        $response = $this->actingAs($user)
            ->patchJson("/api/v1/pallets/{$pallet->id}/bases/{$base->id}/adjust-item", [
                'order_item_id' => $item->id,
                'qty' => 4,
            ]);

        $response->assertOk();
        $response->assertJson(['ok' => true, 'old_qty' => 0, 'new_qty' => 4]);
        $this->assertDatabaseHas('pallet_base_order_items', [
            'base_id' => $base->id, 'order_item_id' => $item->id, 'qty' => 4,
        ]);
        $this->assertDatabaseHas('activity_logs', [
            'user_id' => $user->id,
            'action' => 'item_base_qty_adjusted',
            'entity_type' => 'order_item',
            'entity_id' => $item->id,
            'pallet_id' => $pallet->id,
        ]);
    }

    public function test_adjust_item_recalcula_el_total_al_repetir_el_ajuste(): void
    {
        [$pallet, , $item] = $this->palletWithAssignableItem(qty: 10);
        $base = PalletBase::factory()->for($pallet, 'pallet')->create();
        $base->orderItems()->attach($item->id, ['qty' => 4]);

        $this->actingAs($this->actingUser())
            ->patchJson("/api/v1/pallets/{$pallet->id}/bases/{$base->id}/adjust-item", [
                'order_item_id' => $item->id,
                'qty' => 6,
            ])
            ->assertOk()
            ->assertJson(['old_qty' => 4, 'new_qty' => 6]);

        $this->assertSame(6, (int) DB::table('pallet_base_order_items')
            ->where('order_item_id', $item->id)
            ->sum('qty'));
    }

    public function test_adjust_item_con_qty_cero_elimina_la_fila_pivot(): void
    {
        [$pallet, , $item] = $this->palletWithAssignableItem(qty: 10);
        $base = PalletBase::factory()->for($pallet, 'pallet')->create();
        $base->orderItems()->attach($item->id, ['qty' => 6]);

        $response = $this->actingAs($this->actingUser())
            ->patchJson("/api/v1/pallets/{$pallet->id}/bases/{$base->id}/adjust-item", [
                'order_item_id' => $item->id,
                'qty' => 0,
            ]);

        $response->assertOk();
        $this->assertDatabaseMissing('pallet_base_order_items', [
            'base_id' => $base->id, 'order_item_id' => $item->id,
        ]);
    }

    public function test_adjust_item_rechaza_superar_la_cantidad_pedida(): void
    {
        [$pallet, , $item] = $this->palletWithAssignableItem(qty: 10);
        $base = PalletBase::factory()->for($pallet, 'pallet')->create();

        $response = $this->actingAs($this->actingUser())
            ->patchJson("/api/v1/pallets/{$pallet->id}/bases/{$base->id}/adjust-item", [
                'order_item_id' => $item->id,
                'qty' => 999, // el pedido tiene qty=10
            ]);

        $response->assertConflict()
            ->assertJsonPath('available', 10)
            ->assertJsonPath('requested', 999);
        $this->assertDatabaseMissing('pallet_base_order_items', [
            'base_id' => $base->id, 'order_item_id' => $item->id,
        ]);
    }

    public function test_adjust_item_rechaza_item_de_pedido_no_asociado_al_pallet(): void
    {
        $pallet = Pallet::factory()->create(); // sin pedidos asociados
        $base = PalletBase::factory()->for($pallet, 'pallet')->create();
        $unrelatedItem = OrderItem::factory()->create(['qty' => 10]);

        $response = $this->actingAs($this->actingUser())
            ->patchJson("/api/v1/pallets/{$pallet->id}/bases/{$base->id}/adjust-item", [
                'order_item_id' => $unrelatedItem->id,
                'qty' => 5,
            ]);

        $response->assertConflict()
            ->assertJsonPath('message', 'El producto no pertenece a un pedido asociado a este pallet.');
        $this->assertDatabaseMissing('pallet_base_order_items', [
            'base_id' => $base->id, 'order_item_id' => $unrelatedItem->id,
        ]);
    }

    public function test_adjust_item_considera_asignaciones_en_otros_pallets(): void
    {
        [$pallet, $order, $item] = $this->palletWithAssignableItem(qty: 10);
        $base = PalletBase::factory()->for($pallet, 'pallet')->create();
        $otherPallet = Pallet::factory()->create();
        $otherPallet->orders()->attach($order->id);
        $otherBase = PalletBase::factory()->for($otherPallet, 'pallet')->create();
        $otherBase->orderItems()->attach($item->id, ['qty' => 7]);

        $this->actingAs($this->actingUser())
            ->patchJson("/api/v1/pallets/{$pallet->id}/bases/{$base->id}/adjust-item", [
                'order_item_id' => $item->id,
                'qty' => 4,
            ])
            ->assertConflict()
            ->assertJsonPath('available', 3);

        $this->assertDatabaseMissing('pallet_base_order_items', [
            'base_id' => $base->id, 'order_item_id' => $item->id,
        ]);
    }

    public function test_adjust_item_acepta_el_limite_exacto_global(): void
    {
        [$pallet, $order, $item] = $this->palletWithAssignableItem(qty: 10);
        $base = PalletBase::factory()->for($pallet, 'pallet')->create();
        $otherPallet = Pallet::factory()->create();
        $otherPallet->orders()->attach($order->id);
        $otherBase = PalletBase::factory()->for($otherPallet, 'pallet')->create();
        $otherBase->orderItems()->attach($item->id, ['qty' => 7]);

        $this->actingAs($this->actingUser())
            ->patchJson("/api/v1/pallets/{$pallet->id}/bases/{$base->id}/adjust-item", [
                'order_item_id' => $item->id,
                'qty' => 3,
            ])
            ->assertOk();

        $this->assertSame(10, (int) DB::table('pallet_base_order_items')
            ->where('order_item_id', $item->id)
            ->sum('qty'));
    }

    public function test_adjust_item_mantiene_un_numero_acotado_de_queries_con_muchas_bases(): void
    {
        [$pallet, , $item] = $this->palletWithAssignableItem(qty: 100);
        $base = PalletBase::factory()->for($pallet, 'pallet')->create();

        PalletBase::factory()
            ->for($pallet, 'pallet')
            ->count(20)
            ->create()
            ->each(fn (PalletBase $otherBase) => $otherBase->orderItems()->attach($item->id, ['qty' => 1]));

        $this->actingAs($this->actingUser());
        DB::flushQueryLog();
        DB::enableQueryLog();

        $this->patchJson("/api/v1/pallets/{$pallet->id}/bases/{$base->id}/adjust-item", [
            'order_item_id' => $item->id,
            'qty' => 10,
        ])->assertOk();

        $queryCount = count(DB::getQueryLog());
        DB::disableQueryLog();

        $this->assertSame(9, $queryCount, "El ajuste ejecutó {$queryCount} queries.");
    }

    public function test_adjust_item_revierte_stock_si_falla_el_audit_log(): void
    {
        [$pallet, , $item] = $this->palletWithAssignableItem(qty: 10);
        $base = PalletBase::factory()->for($pallet, 'pallet')->create();
        DB::statement(<<<'SQL'
            CREATE TRIGGER fail_activity_log
            BEFORE INSERT ON activity_logs
            BEGIN
                SELECT RAISE(ABORT, 'forced audit failure');
            END
        SQL);

        $this->actingAs($this->actingUser())
            ->patchJson("/api/v1/pallets/{$pallet->id}/bases/{$base->id}/adjust-item", [
                'order_item_id' => $item->id,
                'qty' => 4,
            ])
            ->assertStatus(500);

        $this->assertDatabaseMissing('pallet_base_order_items', [
            'base_id' => $base->id, 'order_item_id' => $item->id,
        ]);
    }

    // ───────────────────────────────────────────────────────────
    // POST /pallets/{pallet}/bases/{base}/migrate
    // ───────────────────────────────────────────────────────────

    private function baseWithStock(int $qty): array
    {
        [$pallet, $order, $item] = $this->palletWithAssignableItem(qty: $qty);
        $base = PalletBase::factory()->for($pallet, 'pallet')->create();
        $base->orderItems()->attach($item->id, ['qty' => $qty]);

        return [$pallet, $order, $item, $base];
    }

    public function test_migrate_en_base_de_otro_pallet_devuelve_404(): void
    {
        $pallet = Pallet::factory()->create();
        $otherPallet = Pallet::factory()->create();
        $base = PalletBase::factory()->for($otherPallet, 'pallet')->create();
        $item = OrderItem::factory()->create(['qty' => 10]);

        $response = $this->actingAs($this->actingUser())
            ->postJson("/api/v1/pallets/{$pallet->id}/bases/{$base->id}/migrate", [
                'items' => [['order_item_id' => $item->id, 'qty' => 1]],
            ]);

        $response->assertStatus(404);
    }

    public function test_migrate_bloqueado_si_el_pallet_origen_esta_finalizado(): void
    {
        [$pallet, , $item, $base] = $this->baseWithStock(10);
        $pallet->update(['status' => 'done']);

        $response = $this->actingAs($this->actingUser())
            ->postJson("/api/v1/pallets/{$pallet->id}/bases/{$base->id}/migrate", [
                'items' => [['order_item_id' => $item->id, 'qty' => 5]],
            ]);

        $response->assertStatus(422);
    }

    public function test_migrate_con_cantidad_insuficiente_en_la_base_origen_devuelve_422_sin_cambios(): void
    {
        [$pallet, , $item, $base] = $this->baseWithStock(10);

        $response = $this->actingAs($this->actingUser())
            ->postJson("/api/v1/pallets/{$pallet->id}/bases/{$base->id}/migrate", [
                'items' => [['order_item_id' => $item->id, 'qty' => 11]],
            ]);

        $response->assertStatus(422);
        $this->assertDatabaseHas('pallet_base_order_items', [
            'base_id' => $base->id, 'order_item_id' => $item->id, 'qty' => 10,
        ]);
    }

    /**
     * Comportamiento actual: si no se manda destination_pallet_id, migrate
     * SIEMPRE crea un pallet nuevo (nunca reusa el pallet origen ni pide
     * confirmación). Documentado tal cual está.
     */
    public function test_migrate_sin_destino_crea_un_pallet_nuevo_y_mueve_la_cantidad(): void
    {
        [$pallet, , $item, $base] = $this->baseWithStock(10);

        $response = $this->actingAs($this->actingUser())
            ->postJson("/api/v1/pallets/{$pallet->id}/bases/{$base->id}/migrate", [
                'items' => [['order_item_id' => $item->id, 'qty' => 4]],
            ]);

        $response->assertOk();
        $newPalletId = $response->json('destination_pallet_id');
        $this->assertNotEquals($pallet->id, $newPalletId);
        $this->assertDatabaseCount('pallets', 2);

        $this->assertDatabaseHas('pallet_base_order_items', [
            'base_id' => $base->id, 'order_item_id' => $item->id, 'qty' => 6, // 10 - 4
        ]);
    }

    public function test_migrar_toda_la_cantidad_elimina_la_fila_origen_en_vez_de_dejarla_en_cero(): void
    {
        [$pallet, , $item, $base] = $this->baseWithStock(10);

        $this->actingAs($this->actingUser())
            ->postJson("/api/v1/pallets/{$pallet->id}/bases/{$base->id}/migrate", [
                'items' => [['order_item_id' => $item->id, 'qty' => 10]],
            ])
            ->assertOk();

        $this->assertDatabaseMissing('pallet_base_order_items', [
            'base_id' => $base->id, 'order_item_id' => $item->id,
        ]);
    }

    public function test_migrate_a_pallet_y_base_destino_existentes_acumula_la_cantidad(): void
    {
        [$pallet, $order, $item, $base] = $this->baseWithStock(10);
        $destPallet = Pallet::factory()->create();
        $destPallet->orders()->attach($order->id);
        $destBase = PalletBase::factory()->for($destPallet, 'pallet')->create();
        $destBase->orderItems()->attach($item->id, ['qty' => 3]);

        $response = $this->actingAs($this->actingUser())
            ->postJson("/api/v1/pallets/{$pallet->id}/bases/{$base->id}/migrate", [
                'items' => [['order_item_id' => $item->id, 'qty' => 5]],
                'destination_pallet_id' => $destPallet->id,
                'destination_base_id' => $destBase->id,
            ]);

        $response->assertOk();
        $this->assertDatabaseHas('pallet_base_order_items', [
            'base_id' => $destBase->id, 'order_item_id' => $item->id, 'qty' => 8, // 3 + 5
        ]);
    }

    public function test_migrate_asocia_automaticamente_el_pedido_al_pallet_destino(): void
    {
        [$pallet, $order, $item, $base] = $this->baseWithStock(10);

        $response = $this->actingAs($this->actingUser())
            ->postJson("/api/v1/pallets/{$pallet->id}/bases/{$base->id}/migrate", [
                'items' => [['order_item_id' => $item->id, 'qty' => 4]],
            ]);

        $newPalletId = $response->json('destination_pallet_id');
        $this->assertDatabaseHas('order_pallet', [
            'order_id' => $order->id,
            'pallet_id' => $newPalletId,
        ]);
    }
}
