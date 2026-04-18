<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('movements', function (Blueprint $table) {
            $table->id();

            $table->foreignId('pallet_id')->constrained('pallets')->cascadeOnDelete();
            $table->foreignId('order_id')->constrained('orders')->cascadeOnDelete();
            $table->foreignId('product_id')->constrained('products')->restrictOnDelete();

            $table->enum('type', ['ADD', 'REMOVE', 'ADJUST'])->default('ADD');
            $table->integer('qty'); // ADD: +, REMOVE: -, ADJUST: +/- (si querés)
            $table->text('note')->nullable();

            $table->unsignedBigInteger('user_id')->nullable(); // para futuro auth
            $table->timestamps();

            $table->index(['pallet_id', 'created_at']);
            $table->index(['order_id', 'created_at']);
            $table->index(['product_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('movements');
    }
};
