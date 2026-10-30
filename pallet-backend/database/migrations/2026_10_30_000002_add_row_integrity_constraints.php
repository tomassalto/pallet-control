<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * @var array<int, array{table: string, name: string, expression: string, sqlite_expression: string}>
     */
    private array $constraints = [
        [
            'table' => 'order_items',
            'name' => 'order_items_qty_positive_check',
            'expression' => 'qty > 0',
            'sqlite_expression' => 'NEW.qty > 0',
        ],
        [
            'table' => 'order_items',
            'name' => 'order_items_done_qty_range_check',
            'expression' => 'done_qty >= 0 AND done_qty <= qty',
            'sqlite_expression' => 'NEW.done_qty >= 0 AND NEW.done_qty <= NEW.qty',
        ],
        [
            'table' => 'pallet_base_order_items',
            'name' => 'pallet_base_order_items_qty_positive_check',
            'expression' => 'qty > 0',
            'sqlite_expression' => 'NEW.qty > 0',
        ],
        [
            'table' => 'pending_items',
            'name' => 'pending_items_qty_missing_positive_check',
            'expression' => 'qty_missing > 0',
            'sqlite_expression' => 'NEW.qty_missing > 0',
        ],
        [
            'table' => 'pending_items',
            'name' => 'pending_items_status_check',
            'expression' => "status IN ('pending', 'resolved')",
            'sqlite_expression' => "NEW.status IN ('pending', 'resolved')",
        ],
        [
            'table' => 'missing_item_requests',
            'name' => 'missing_item_requests_qty_missing_positive_check',
            'expression' => 'qty_missing > 0',
            'sqlite_expression' => 'NEW.qty_missing > 0',
        ],
        [
            'table' => 'missing_item_requests',
            'name' => 'missing_item_requests_status_check',
            'expression' => "status IN ('pending_review', 'approved', 'rejected')",
            'sqlite_expression' => "NEW.status IN ('pending_review', 'approved', 'rejected')",
        ],
    ];

    public function up(): void
    {
        $driver = DB::getDriverName();
        if (! in_array($driver, ['pgsql', 'sqlite'], true)) {
            throw new RuntimeException("Driver no soportado para constraints de integridad: {$driver}.");
        }

        foreach ($this->constraints as $constraint) {
            $invalidId = DB::table($constraint['table'])
                ->whereRaw("NOT ({$constraint['expression']})")
                ->value('id');

            if ($invalidId !== null) {
                throw new RuntimeException(
                    "No se puede crear {$constraint['name']}: {$constraint['table']}.id={$invalidId} viola la regla."
                );
            }
        }

        foreach ($this->constraints as $constraint) {
            if ($driver === 'pgsql') {
                $this->addPostgresConstraint($constraint);
            } else {
                $this->addSqliteTriggers($constraint);
            }
        }
    }

    public function down(): void
    {
        $driver = DB::getDriverName();

        foreach (array_reverse($this->constraints) as $constraint) {
            if ($driver === 'pgsql') {
                DB::statement(
                    "ALTER TABLE \"{$constraint['table']}\" DROP CONSTRAINT IF EXISTS \"{$constraint['name']}\""
                );
            } elseif ($driver === 'sqlite') {
                DB::statement("DROP TRIGGER IF EXISTS \"{$constraint['name']}_insert\"");
                DB::statement("DROP TRIGGER IF EXISTS \"{$constraint['name']}_update\"");
            }
        }
    }

    /**
     * @param array{table: string, name: string, expression: string, sqlite_expression: string} $constraint
     */
    private function addPostgresConstraint(array $constraint): void
    {
        DB::statement(
            "ALTER TABLE \"{$constraint['table']}\" ADD CONSTRAINT \"{$constraint['name']}\" " .
            "CHECK ({$constraint['expression']}) NOT VALID"
        );
        DB::statement(
            "ALTER TABLE \"{$constraint['table']}\" VALIDATE CONSTRAINT \"{$constraint['name']}\""
        );
    }

    /**
     * @param array{table: string, name: string, expression: string, sqlite_expression: string} $constraint
     */
    private function addSqliteTriggers(array $constraint): void
    {
        foreach (['insert' => 'INSERT', 'update' => 'UPDATE'] as $suffix => $event) {
            DB::statement(
                "CREATE TRIGGER \"{$constraint['name']}_{$suffix}\" " .
                "BEFORE {$event} ON \"{$constraint['table']}\" FOR EACH ROW " .
                "WHEN NOT ({$constraint['sqlite_expression']}) BEGIN " .
                "SELECT RAISE(ABORT, '{$constraint['name']}'); END"
            );
        }
    }
};
