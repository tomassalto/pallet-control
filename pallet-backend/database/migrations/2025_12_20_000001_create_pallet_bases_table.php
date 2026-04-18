<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('pallet_bases', function (Blueprint $table) {
            $table->id();
            $table->foreignId('pallet_id')->constrained('pallets')->cascadeOnDelete();
            $table->string('name')->nullable(); // opcional: "Base 1", "Base A", etc.
            $table->text('note')->nullable(); // descripción de pedidos: "28 cajas de cerveza patagonia, 28 x 6 = 168 unidades"
            $table->timestamps();

            $table->index(['pallet_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pallet_bases');
    }
};
