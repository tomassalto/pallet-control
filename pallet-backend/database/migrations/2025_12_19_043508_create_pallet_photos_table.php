<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('pallet_photos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('pallet_id')->constrained('pallets')->cascadeOnDelete();
            $table->string('path');         // storage path
            $table->string('original_name')->nullable();
            $table->text('note')->nullable();
            $table->timestamps();

            $table->index(['pallet_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pallet_photos');
    }
};
