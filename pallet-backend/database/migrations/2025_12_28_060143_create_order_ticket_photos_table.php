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
        Schema::create('order_ticket_photos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ticket_id')->constrained('order_tickets')->onDelete('cascade');
            $table->string('path'); // Ruta de la foto
            $table->string('original_name')->nullable(); // Nombre original del archivo
            $table->text('note')->nullable(); // Nota opcional
            $table->integer('order_index')->default(0); // Orden de las fotos (para tickets largos)
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('order_ticket_photos');
    }
};
