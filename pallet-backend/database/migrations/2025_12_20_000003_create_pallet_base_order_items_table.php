<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('pallet_base_order_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('base_id')->constrained('pallet_bases')->cascadeOnDelete();
            $table->foreignId('order_item_id')->constrained('order_items')->cascadeOnDelete();
            $table->integer('qty')->default(1); // cantidad de este item en esta base específica
            $table->timestamps();

            $table->unique(['base_id', 'order_item_id']); // un item solo puede estar una vez por base
            $table->index(['base_id']);
            $table->index(['order_item_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pallet_base_order_items');
    }
};
