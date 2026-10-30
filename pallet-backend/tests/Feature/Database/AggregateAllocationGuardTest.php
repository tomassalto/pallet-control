<?php

namespace Tests\Feature\Database;

use App\Models\OrderItem;
use App\Models\PalletBase;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class AggregateAllocationGuardTest extends TestCase
{
    use RefreshDatabase;

    public function test_asignacion_repartida_en_dos_pallets_no_puede_superar_lo_pedido(): void
    {
        $item = OrderItem::factory()->create(['qty' => 10]);
        $baseA = PalletBase::factory()->create();
        $baseB = PalletBase::factory()->create();

        $this->assertNotSame($baseA->pallet_id, $baseB->pallet_id);

        $this->allocation($baseA->id, $item->id, 6);

        try {
            $this->allocation($baseB->id, $item->id, 6);
            $this->fail('La segunda asignación debía ser rechazada por la guarda agregada.');
        } catch (QueryException $exception) {
            $this->assertStringContainsString('assigned_exceeds_ordered', $exception->getMessage());
        }

        // La fila rechazada no quedó escrita.
        $this->assertSame(6, $this->assignedTotal($item->id));
    }

    public function test_asignacion_hasta_el_limite_exacto_es_aceptada(): void
    {
        $item = OrderItem::factory()->create(['qty' => 10]);
        $baseA = PalletBase::factory()->create();
        $baseB = PalletBase::factory()->create();

        $this->allocation($baseA->id, $item->id, 6);
        $this->allocation($baseB->id, $item->id, 4);

        $this->assertSame(10, $this->assignedTotal($item->id));
    }

    public function test_actualizar_una_asignacion_por_encima_del_limite_es_rechazado(): void
    {
        $item = OrderItem::factory()->create(['qty' => 10]);
        $baseA = PalletBase::factory()->create();
        $baseB = PalletBase::factory()->create();

        $this->allocation($baseA->id, $item->id, 6);
        $this->allocation($baseB->id, $item->id, 4);

        try {
            DB::table('pallet_base_order_items')
                ->where('base_id', $baseB->id)
                ->where('order_item_id', $item->id)
                ->update(['qty' => 6]);
            $this->fail('La actualización debía ser rechazada por la guarda agregada.');
        } catch (QueryException $exception) {
            $this->assertStringContainsString('assigned_exceeds_ordered', $exception->getMessage());
        }

        $this->assertSame(10, $this->assignedTotal($item->id));
    }

    public function test_reducir_qty_por_debajo_de_lo_asignado_es_rechazado(): void
    {
        $item = OrderItem::factory()->create(['qty' => 10]);
        $base = PalletBase::factory()->create();
        $this->allocation($base->id, $item->id, 6);

        try {
            DB::table('order_items')->where('id', $item->id)->update(['qty' => 5]);
            $this->fail('La reducción debía ser rechazada por la guarda agregada.');
        } catch (QueryException $exception) {
            $this->assertStringContainsString('assigned_exceeds_ordered', $exception->getMessage());
        }

        $this->assertSame(10, (int) DB::table('order_items')->where('id', $item->id)->value('qty'));

        // Exactamente lo asignado sí es válido.
        DB::table('order_items')->where('id', $item->id)->update(['qty' => 6]);
        $this->assertSame(6, (int) DB::table('order_items')->where('id', $item->id)->value('qty'));
    }

    public function test_preflight_de_la_migracion_aborta_con_sobreasignacion(): void
    {
        $item = OrderItem::factory()->create(['qty' => 10]);
        $base = PalletBase::factory()->create();
        $baseB = PalletBase::factory()->create();
        $this->allocation($base->id, $item->id, 6);

        $migration = require database_path('migrations/2026_10_30_000004_add_aggregate_allocation_guard.php');

        // Sin los triggers se puede crear la sobreasignación que el
        // preflight debe detectar.
        $migration->down();
        DB::table('pallet_base_order_items')->insert([
            'base_id' => $baseB->id,
            'order_item_id' => $item->id,
            'qty' => 6,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        try {
            $migration->up();
            $this->fail('El preflight debía rechazar la sobreasignación existente.');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString('sobreasignados', $exception->getMessage());
            $this->assertStringContainsString((string) $item->id, $exception->getMessage());
        }

        // La migración abortó antes de crear cualquier trigger.
        $this->assertSame(
            [],
            DB::select("SELECT name FROM sqlite_master WHERE type = 'trigger' AND name LIKE 'trg_%'"),
        );

        // Corregido el dato (6+3 ≤ 10), la migración se aplica y la
        // guarda vuelve a operar.
        DB::table('pallet_base_order_items')
            ->where('base_id', $baseB->id)
            ->update(['qty' => 3]);

        $migration->up();

        $baseC = PalletBase::factory()->create();
        try {
            $this->allocation($baseC->id, $item->id, 9);
            $this->fail('La guarda debía volver a estar activa tras re-migrar.');
        } catch (QueryException) {
        }
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
}
