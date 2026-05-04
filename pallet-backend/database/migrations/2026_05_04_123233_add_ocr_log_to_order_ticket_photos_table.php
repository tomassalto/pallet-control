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
        Schema::table('order_ticket_photos', function (Blueprint $table) {
            $table->text('ocr_log')->nullable()->after('ocr_processed_at');
        });
    }

    public function down(): void
    {
        Schema::table('order_ticket_photos', function (Blueprint $table) {
            $table->dropColumn('ocr_log');
        });
    }
};
