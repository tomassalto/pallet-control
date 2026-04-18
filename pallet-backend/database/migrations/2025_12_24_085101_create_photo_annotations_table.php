<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('photo_annotations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('photo_id')->constrained('pallet_base_photos')->cascadeOnDelete();
            $table->string('text'); // El número o texto de la anotación
            $table->decimal('x', 10, 2); // Posición X
            $table->decimal('y', 10, 2); // Posición Y
            $table->integer('font_size')->default(36);
            $table->string('color', 20)->default('#ffffff');
            $table->timestamps();

            $table->index(['photo_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('photo_annotations');
    }
};
