<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pending_file_deletions', function (Blueprint $table) {
            // Lease del reclamo: un worker toma la intención con un token
            // único y una caducidad; si muere, el barrido la reclama al vencer.
            $table->string('claim_token', 36)->nullable()->after('completed_at');
            $table->timestamp('claimed_until')->nullable()->after('claim_token');
        });
    }

    public function down(): void
    {
        Schema::table('pending_file_deletions', function (Blueprint $table) {
            $table->dropColumn(['claim_token', 'claimed_until']);
        });
    }
};
