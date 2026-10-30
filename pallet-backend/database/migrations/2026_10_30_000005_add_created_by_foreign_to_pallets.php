<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $orphans = DB::table('pallets')
            ->whereNotNull('created_by')
            ->whereNotExists(function ($query) {
                $query->selectRaw('1')
                    ->from('users')
                    ->whereColumn('users.id', 'pallets.created_by');
            })
            ->limit(20)
            ->pluck('id');

        if ($orphans->isNotEmpty()) {
            throw new RuntimeException(
                'No se puede crear la FK pallets/created_by: referencias a usuarios inexistentes en pallets ' .
                $orphans->implode(', ')
            );
        }

        Schema::table('pallets', function (Blueprint $table) {
            $table->index('created_by', 'pallets_created_by_index');
            $table->foreign('created_by', 'pallets_created_by_foreign')
                ->references('id')
                ->on('users')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('pallets', function (Blueprint $table) {
            // Por columna: el nombre resuelto es pallets_created_by_foreign
            // en ambos motores, y SQLite no admite borrar FKs por nombre.
            $table->dropForeign(['created_by']);
            $table->dropIndex('pallets_created_by_index');
        });
    }
};
