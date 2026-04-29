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
            // EANs detectados con sus coordenadas de bounding box
            $table->json('ocr_data')->nullable()->after('order_index');
            // null = no procesado, timestamp = procesado (incluso si no se encontraron EANs)
            $table->timestamp('ocr_processed_at')->nullable()->after('ocr_data');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('order_ticket_photos', function (Blueprint $table) {
            $table->dropColumn(['ocr_data', 'ocr_processed_at']);
        });
    }
};
