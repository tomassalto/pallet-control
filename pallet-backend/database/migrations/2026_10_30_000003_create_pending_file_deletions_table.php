<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pending_file_deletions', function (Blueprint $table) {
            $table->id();
            $table->string('disk', 64);
            $table->string('path', 1024);
            $table->string('reason', 64);
            $table->unsignedInteger('attempts')->default(0);
            // Solo la clase de la excepción o 'delete_returned_false'; nunca mensajes ni secretos.
            $table->string('last_error', 255)->nullable();
            $table->timestamp('available_at');
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->unique(['disk', 'path']);
            $table->index(['completed_at', 'available_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pending_file_deletions');
    }
};
