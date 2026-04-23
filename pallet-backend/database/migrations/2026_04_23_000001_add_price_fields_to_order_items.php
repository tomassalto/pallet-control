<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('order_items', function (Blueprint $table) {
            $table->decimal('price', 10, 2)->nullable()->after('qty');
            $table->decimal('desc_medio_pago', 10, 2)->nullable()->after('price');
            $table->boolean('is_controlled')->default(false)->after('desc_medio_pago');
        });
    }

    public function down(): void
    {
        Schema::table('order_items', function (Blueprint $table) {
            $table->dropColumn(['price', 'desc_medio_pago', 'is_controlled']);
        });
    }
};
