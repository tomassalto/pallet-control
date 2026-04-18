<?php

// database/migrations/xxxx_xx_xx_create_order_items_table.php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('order_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained('orders')->cascadeOnDelete();

            $table->string('ean', 32);
            $table->string('ean_last4', 4)->nullable(); // ✅ sin after()
            $table->string('description');
            $table->integer('qty');

            $table->enum('status', ['pending', 'done', 'removed'])->default('pending'); // ✅ sin after()
            $table->integer('done_qty')->default(0); // ✅ sin after()

            $table->timestamps();

            $table->index(['order_id', 'status']);
            $table->index('ean_last4');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('order_items');
    }
};
