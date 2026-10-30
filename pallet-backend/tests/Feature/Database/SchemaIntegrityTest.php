<?php

namespace Tests\Feature\Database;

use App\Models\OrderItem;
use App\Models\PalletBase;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class SchemaIntegrityTest extends TestCase
{
    use RefreshDatabase;

    #[DataProvider('foreignIndexProvider')]
    public function test_foreign_columns_have_a_covering_index(string $table, string $column): void
    {
        $isCovered = collect(Schema::getIndexes($table))->contains(
            fn (array $index) => ($index['columns'][0] ?? null) === $column
        );

        $this->assertTrue($isCovered, "{$table}.{$column} no tiene un índice que comience por la FK.");
    }

    public function test_activity_log_pallet_reference_is_a_real_foreign_key(): void
    {
        $foreignKey = collect(Schema::getForeignKeys('activity_logs'))->first(
            fn (array $foreign) => $foreign['columns'] === ['pallet_id']
        );

        $this->assertNotNull($foreignKey);
        $this->assertSame('pallets', $foreignKey['foreign_table']);

        $palletId = DB::table('pallets')->insertGetId([
            'code' => 'PAL-FK-TEST',
            'status' => 'open',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $logId = DB::table('activity_logs')->insertGetId([
            'action' => 'schema_test',
            'entity_type' => 'pallet',
            'entity_id' => $palletId,
            'pallet_id' => $palletId,
            'description' => 'Prueba de integridad de la referencia al pallet.',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('pallets')->where('id', $palletId)->delete();

        $this->assertNull(DB::table('activity_logs')->where('id', $logId)->value('pallet_id'));
    }

    public function test_row_constraint_migration_has_preflight_and_recovery(): void
    {
        $item = OrderItem::factory()->create(['qty' => 10, 'done_qty' => 0]);
        $migration = require database_path('migrations/2026_10_30_000002_add_row_integrity_constraints.php');

        $migration->down();
        DB::table('order_items')->where('id', $item->id)->update(['qty' => 0]);

        try {
            $migration->up();
            $this->fail('El preflight debía rechazar la fila inválida.');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString('order_items_qty_positive_check', $exception->getMessage());
        }

        DB::table('order_items')->where('id', $item->id)->update(['qty' => 10]);
        $migration->up();
    }

    #[DataProvider('invalidRowProvider')]
    public function test_row_constraints_reject_invalid_data(string $case): void
    {
        $user = User::factory()->create();
        $item = OrderItem::factory()->create(['qty' => 10, 'done_qty' => 0]);
        $base = PalletBase::factory()->create();

        $this->expectException(QueryException::class);

        match ($case) {
            'order_qty' => DB::table('order_items')->where('id', $item->id)->update(['qty' => 0]),
            'done_qty' => DB::table('order_items')->where('id', $item->id)->update(['done_qty' => 11]),
            'assigned_qty' => DB::table('pallet_base_order_items')->insert([
                'base_id' => $base->id,
                'order_item_id' => $item->id,
                'qty' => 0,
                'created_at' => now(),
                'updated_at' => now(),
            ]),
            'pending_qty' => $this->insertPending($item, $user, 0, 'pending'),
            'pending_status' => $this->insertPending($item, $user, 1, 'invalid'),
            'request_qty' => $this->insertRequest($item, 0, 'pending_review'),
            'request_status' => $this->insertRequest($item, 1, 'invalid'),
        };
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function foreignIndexProvider(): array
    {
        return [
            'orders.customer_id' => ['orders', 'customer_id'],
            'order_tickets.order_id' => ['order_tickets', 'order_id'],
            'order_ticket_photos.ticket_id' => ['order_ticket_photos', 'ticket_id'],
            'pending_items.order_id' => ['pending_items', 'order_id'],
            'pending_items.order_item_id' => ['pending_items', 'order_item_id'],
            'pending_items.resolved_by' => ['pending_items', 'resolved_by'],
            'pending_items.created_by' => ['pending_items', 'created_by'],
            'missing_item_requests.order_item_id' => ['missing_item_requests', 'order_item_id'],
            'missing_item_requests.reviewed_by' => ['missing_item_requests', 'reviewed_by'],
        ];
    }

    /**
     * @return array<string, array{string}>
     */
    public static function invalidRowProvider(): array
    {
        return [
            'cantidad pedida positiva' => ['order_qty'],
            'cantidad completada dentro del pedido' => ['done_qty'],
            'cantidad asignada positiva' => ['assigned_qty'],
            'cantidad pendiente positiva' => ['pending_qty'],
            'estado pendiente permitido' => ['pending_status'],
            'cantidad solicitada positiva' => ['request_qty'],
            'estado de solicitud permitido' => ['request_status'],
        ];
    }

    // ── D-021: atribución pallets.created_by e índice único de auditoría ──

    public function test_pallets_created_by_tiene_fk_null_on_delete_e_indice(): void
    {
        $foreignKey = collect(Schema::getForeignKeys('pallets'))->first(
            fn (array $foreign) => $foreign['columns'] === ['created_by']
        );

        $this->assertNotNull($foreignKey, 'Falta la FK pallets.created_by → users.');
        $this->assertSame('users', $foreignKey['foreign_table']);
        $this->assertSame(['id'], $foreignKey['foreign_columns']);
        $this->assertStringContainsStringIgnoringCase(
            'set null',
            (string) $foreignKey['on_delete'],
        );

        $this->assertTrue(
            collect(Schema::getIndexes('pallets'))->contains(
                fn (array $index) => $index['name'] === 'pallets_created_by_index'
                    && $index['columns'] === ['created_by']
            ),
            'Falta el índice pallets_created_by_index.',
        );

        // Comportamiento: borrar el usuario anula la referencia.
        $user = User::factory()->create();
        $palletId = DB::table('pallets')->insertGetId([
            'code' => 'PAL-FK-CB',
            'status' => 'open',
            'created_by' => $user->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('users')->where('id', $user->id)->delete();

        $this->assertNull(DB::table('pallets')->where('id', $palletId)->value('created_by'));
    }

    public function test_activity_logs_tiene_un_solo_indice_que_empieza_por_order_id(): void
    {
        // H-016: la segunda migración usa CREATE INDEX IF NOT EXISTS con el
        // mismo nombre y no duplica. Este test fija exactamente un índice
        // cuya primera columna sea order_id en ambos motores.
        $leading = collect(Schema::getIndexes('activity_logs'))
            ->filter(fn (array $index) => ($index['columns'][0] ?? null) === 'order_id')
            ->values();

        $this->assertCount(1, $leading);
    }

    public function test_created_by_migration_preflight_aborta_con_huerfanos(): void
    {
        $migration = require database_path('migrations/2026_10_30_000005_add_created_by_foreign_to_pallets.php');

        $migration->down();

        $palletId = DB::table('pallets')->insertGetId([
            'code' => 'PAL-ORPHAN',
            'status' => 'open',
            'created_by' => 999_999,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        try {
            $migration->up();
            $this->fail('El preflight debía rechazar la referencia huérfana.');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString((string) $palletId, $exception->getMessage());
        }

        // Abortó antes de crear nada.
        $this->assertFalse(
            collect(Schema::getIndexes('pallets'))->contains(
                fn (array $index) => $index['name'] === 'pallets_created_by_index'
            ),
        );

        // Corregido el dato, la migración se aplica completa.
        DB::table('pallets')->where('id', $palletId)->update(['created_by' => null]);
        $migration->up();

        $this->assertTrue(
            collect(Schema::getIndexes('pallets'))->contains(
                fn (array $index) => $index['name'] === 'pallets_created_by_index'
            ),
        );
    }

    private function insertPending(OrderItem $item, User $user, int $qty, string $status): void
    {
        DB::table('pending_items')->insert([
            'order_id' => $item->order_id,
            'order_item_id' => $item->id,
            'qty_missing' => $qty,
            'status' => $status,
            'created_by' => $user->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function insertRequest(OrderItem $item, int $qty, string $status): void
    {
        DB::table('missing_item_requests')->insert([
            'order_id' => $item->order_id,
            'order_item_id' => $item->id,
            'qty_missing' => $qty,
            'status' => $status,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
