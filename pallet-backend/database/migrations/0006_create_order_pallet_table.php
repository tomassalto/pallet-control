<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('order_pallet', function (Blueprint $table) {
            $table->id();

            $table->foreignId('order_id')->constrained('orders')->cascadeOnDelete();
            $table->foreignId('pallet_id')->constrained('pallets')->cascadeOnDelete();

            $table->timestamps();

            $table->unique(['order_id', 'pallet_id']);
            $table->index(['pallet_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('order_pallet');
    }
};
