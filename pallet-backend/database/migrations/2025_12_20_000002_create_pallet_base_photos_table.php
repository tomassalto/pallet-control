<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('pallet_base_photos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('base_id')->constrained('pallet_bases')->cascadeOnDelete();
            $table->string('path'); // storage path
            $table->string('original_name')->nullable();
            $table->text('note')->nullable();
            $table->timestamps();

            $table->index(['base_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pallet_base_photos');
    }
};
