<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->string('public_token', 64)->nullable()->after('code');
        });

        DB::table('orders')->orderBy('id')->chunkById(100, function ($orders): void {
            foreach ($orders as $order) {
                do {
                    $token = Str::random(48);
                } while (DB::table('orders')->where('public_token', $token)->exists());

                DB::table('orders')->where('id', $order->id)->update(['public_token' => $token]);
            }
        });

        Schema::table('orders', function (Blueprint $table) {
            $table->unique('public_token', 'orders_public_token_unique');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropUnique('orders_public_token_unique');
            $table->dropColumn('public_token');
        });
    }
};
