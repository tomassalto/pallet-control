<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * @var array<string, array<string, string>>
     */
    private array $indexes = [
        'orders' => [
            'customer_id' => 'orders_customer_id_index',
        ],
        'order_tickets' => [
            'order_id' => 'order_tickets_order_id_index',
        ],
        'order_ticket_photos' => [
            'ticket_id' => 'order_ticket_photos_ticket_id_index',
        ],
        'pending_items' => [
            'order_id' => 'pending_items_order_id_index',
            'order_item_id' => 'pending_items_order_item_id_index',
            'resolved_by' => 'pending_items_resolved_by_index',
            'created_by' => 'pending_items_created_by_index',
        ],
        'missing_item_requests' => [
            'order_item_id' => 'missing_item_requests_order_item_id_index',
            'reviewed_by' => 'missing_item_requests_reviewed_by_index',
        ],
    ];

    public function up(): void
    {
        $orphan = DB::table('activity_logs')
            ->whereNotNull('pallet_id')
            ->whereNotExists(function ($query) {
                $query->selectRaw('1')
                    ->from('pallets')
                    ->whereColumn('pallets.id', 'activity_logs.pallet_id');
            })
            ->value('pallet_id');

        if ($orphan !== null) {
            throw new RuntimeException(
                "No se puede crear la FK activity_logs/pallet: existe una referencia huérfana al pallet {$orphan}."
            );
        }

        foreach ($this->indexes as $tableName => $indexes) {
            Schema::table($tableName, function (Blueprint $table) use ($indexes) {
                foreach ($indexes as $column => $indexName) {
                    $table->index($column, $indexName);
                }
            });
        }

        Schema::table('activity_logs', function (Blueprint $table) {
            $table->foreign('pallet_id', 'activity_logs_pallet_id_foreign')
                ->references('id')
                ->on('pallets')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('activity_logs', function (Blueprint $table) {
            $table->dropForeign('activity_logs_pallet_id_foreign');
        });

        foreach (array_reverse($this->indexes, true) as $tableName => $indexes) {
            Schema::table($tableName, function (Blueprint $table) use ($indexes) {
                foreach (array_reverse($indexes) as $indexName) {
                    $table->dropIndex($indexName);
                }
            });
        }
    }
};
