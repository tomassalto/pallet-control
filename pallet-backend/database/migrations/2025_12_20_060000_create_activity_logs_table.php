<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('activity_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('action'); // 'item_quantity_changed', 'item_status_changed', 'item_removed', 'item_added', 'base_created', 'base_updated', 'base_deleted', 'order_assigned', etc.
            $table->string('entity_type'); // 'order_item', 'pallet_base', 'pallet', 'order', etc.
            $table->unsignedBigInteger('entity_id')->nullable();
            $table->unsignedBigInteger('pallet_id')->nullable();
            $table->text('description'); // Descripción legible de la acción
            $table->json('old_values')->nullable(); // Valores anteriores (para cambios)
            $table->json('new_values')->nullable(); // Valores nuevos (para cambios)
            $table->timestamps();

            $table->index(['pallet_id', 'created_at']);
            $table->index(['entity_type', 'entity_id']);
            $table->index('user_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('activity_logs');
    }
};
