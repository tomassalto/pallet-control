<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $duplicate = DB::table('order_items')
            ->select(['order_id', 'ean'])
            ->selectRaw('COUNT(*) AS duplicate_count')
            ->groupBy('order_id', 'ean')
            ->havingRaw('COUNT(*) > 1')
            ->first();

        if ($duplicate) {
            throw new RuntimeException(
                "No se puede crear la unicidad order_id/EAN: el pedido {$duplicate->order_id} contiene el EAN {$duplicate->ean} repetido."
            );
        }

        Schema::table('order_items', function (Blueprint $table) {
            $table->unique(['order_id', 'ean'], 'order_items_order_id_ean_unique');
        });
    }

    public function down(): void
    {
        Schema::table('order_items', function (Blueprint $table) {
            $table->dropUnique('order_items_order_id_ean_unique');
        });
    }
};
