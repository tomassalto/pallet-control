<?php

namespace Tests\Feature\Database;

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
}
