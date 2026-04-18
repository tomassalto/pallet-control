<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('products', function (Blueprint $table) {
            $table->id();

            $table->string('ean', 32)->unique();
            $table->string('ean_last4', 4)->nullable(); // ✅ sin after()
            $table->string('name');

            $table->timestamps();

            $table->index(['name']);
            $table->index('ean_last4');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('products');
    }
};
