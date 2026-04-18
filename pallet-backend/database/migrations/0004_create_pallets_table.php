<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('pallets', function (Blueprint $table) {
            $table->id();

            $table->string('code')->unique(); // ej: PAL-20251217-0001
            $table->enum('status', ['open', 'paused', 'done'])->default('open');
            $table->text('note')->nullable();

            $table->unsignedBigInteger('created_by')->nullable(); // para futuro auth
            $table->timestamps();

            $table->index(['status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pallets');
    }
};
