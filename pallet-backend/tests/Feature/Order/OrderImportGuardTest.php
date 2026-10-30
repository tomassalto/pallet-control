<?php

namespace Tests\Feature\Order;

use App\Models\Order;
use App\Models\OrderItem;
use App\Models\PalletBase;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class OrderImportGuardTest extends TestCase
{
    use RefreshDatabase;

    public function test_import_que_reduce_por_debajo_de_lo_asignado_devuelve_409_sin_cambios(): void
    {
        $user = User::factory()->withRole()->create();
        $order = Order::factory()->create();
        $item = OrderItem::factory()->for($order)->create(['ean' => '7790000000001', 'qty' => 10]);
        $base = PalletBase::factory()->create();
        $this->allocation($base->id, $item->id, 6);

        $productsBefore = DB::table('products')->count();
        $itemsBefore = DB::table('order_items')->count();

        $this->actingAs($user)
            ->postJson("/api/v1/orders/{$order->id}/import", [
                'raw' => $this->rawLine('7790000000001', 'Producto A', 4),
            ])
            ->assertStatus(409)
            ->assertJsonPath('message', 'La importación reduce cantidades por debajo de lo ya asignado a pallets.')
            ->assertJsonPath('conflicts.0.ean', '7790000000001')
            ->assertJsonPath('conflicts.0.assigned', 6)
            ->assertJsonPath('conflicts.0.requested', 4);

        // Nada se escribió: qty intacta, ítems y productos sin tocar, sin log.
        $this->assertSame(10, (int) $item->fresh()->qty);
        $this->assertSame($itemsBefore, DB::table('order_items')->count());
        $this->assertSame($productsBefore, DB::table('products')->count());
        $this->assertDatabaseMissing('activity_logs', ['action' => 'order_imported']);
        $this->assertSame(6, $this->assignedTotal($item->id));
    }

    public function test_import_que_reduce_a_exactamente_lo_asignado_es_aceptado(): void
    {
        $user = User::factory()->withRole()->create();
        $order = Order::factory()->create();
        $item = OrderItem::factory()->for($order)->create(['ean' => '7790000000001', 'qty' => 10]);
        $base = PalletBase::factory()->create();
        $this->allocation($base->id, $item->id, 6);

        $this->actingAs($user)
            ->postJson("/api/v1/orders/{$order->id}/import", [
                'raw' => $this->rawLine('7790000000001', 'Producto A', 6),
            ])
            ->assertStatus(201);

        $this->assertSame(6, (int) $item->fresh()->qty);
        $this->assertDatabaseHas('activity_logs', ['action' => 'order_imported', 'order_id' => $order->id]);
    }

    public function test_import_normal_reescribe_items_y_audita(): void
    {
        $user = User::factory()->withRole()->create();
        $order = Order::factory()->create();
        OrderItem::factory()->for($order)->create(['ean' => '7790000000099', 'qty' => 3]);

        $this->actingAs($user)
            ->postJson("/api/v1/orders/{$order->id}/import", [
                'raw' => $this->rawLine('7790000000001', 'Producto A', 5)
                    . "\n" . $this->rawLine('7790000000002', 'Producto B', 7),
            ])
            ->assertStatus(201)
            ->assertJsonPath('count', 2);

        // El ítem ausente del import se eliminó; los dos nuevos quedaron.
        $this->assertDatabaseMissing('order_items', ['order_id' => $order->id, 'ean' => '7790000000099']);
        $this->assertDatabaseHas('order_items', ['order_id' => $order->id, 'ean' => '7790000000001', 'qty' => 5]);
        $this->assertDatabaseHas('order_items', ['order_id' => $order->id, 'ean' => '7790000000002', 'qty' => 7]);
        $this->assertDatabaseHas('activity_logs', [
            'action' => 'order_imported',
            'entity_type' => 'order',
            'entity_id' => $order->id,
        ]);
    }

    public function test_import_con_fallo_de_auditoria_no_cambia_nada(): void
    {
        $user = User::factory()->withRole()->create();
        $order = Order::factory()->create();
        $item = OrderItem::factory()->for($order)->create(['ean' => '7790000000099', 'qty' => 3]);
        $productsBefore = DB::table('products')->count();
        $this->failActivityLogs();

        $this->actingAs($user)
            ->postJson("/api/v1/orders/{$order->id}/import", [
                'raw' => $this->rawLine('7790000000001', 'Producto A', 5),
            ])
            ->assertStatus(500);

        // Todo revertido: el ítem viejo sigue, no hay ítem nuevo ni producto.
        $this->assertDatabaseHas('order_items', ['id' => $item->id, 'ean' => '7790000000099', 'qty' => 3]);
        $this->assertDatabaseMissing('order_items', ['ean' => '7790000000001']);
        $this->assertSame($productsBefore, DB::table('products')->count());
        $this->assertDatabaseCount('activity_logs', 0);
    }

    private function rawLine(string $ean, string $description, int $cantReal): string
    {
        // Columnas TAB: EAN, Descripción, Cant Pedida, Cant Real, …
        return implode("\t", [$ean, $description, $cantReal, $cantReal, '', '', '', '', '']);
    }

    private function allocation(int $baseId, int $itemId, int $qty): void
    {
        DB::table('pallet_base_order_items')->insert([
            'base_id' => $baseId,
            'order_item_id' => $itemId,
            'qty' => $qty,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function assignedTotal(int $itemId): int
    {
        return (int) DB::table('pallet_base_order_items')
            ->where('order_item_id', $itemId)
            ->sum('qty');
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
