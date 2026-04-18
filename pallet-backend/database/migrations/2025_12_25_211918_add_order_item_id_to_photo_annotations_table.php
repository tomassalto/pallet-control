<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('photo_annotations', function (Blueprint $table) {
            $table->foreignId('order_item_id')
                ->nullable()
                ->after('photo_id')
                ->constrained('order_items')
                ->nullOnDelete();
            
            $table->index(['order_item_id']);
        });
    }

    public function down(): void
    {
        Schema::table('photo_annotations', function (Blueprint $table) {
            $table->dropForeign(['order_item_id']);
            $table->dropIndex(['order_item_id']);
            $table->dropColumn('order_item_id');
        });
    }
};
