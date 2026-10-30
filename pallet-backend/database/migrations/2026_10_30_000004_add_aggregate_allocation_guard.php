<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Guarda agregada D-020: la suma de pallet_base_order_items.qty por
     * order_item nunca puede superar order_items.qty. Ambos motores
     * serializan la comprobación; en PostgreSQL el trigger bloquea la fila
     * del ítem con FOR UPDATE (los recorridos ya poseen ese lock), en
     * SQLite el propio motor serializa las escrituras.
     */
    public function up(): void
    {
        $driver = DB::getDriverName();
        if (! in_array($driver, ['pgsql', 'sqlite'], true)) {
            throw new RuntimeException("Driver no soportado para la guarda agregada: {$driver}.");
        }

        // Preflight: no se crea ninguna regla si ya hay sobreasignación.
        $violations = DB::table('order_items')
            ->select('order_items.id')
            ->leftJoin('pallet_base_order_items', 'pallet_base_order_items.order_item_id', '=', 'order_items.id')
            ->groupBy('order_items.id', 'order_items.qty')
            ->havingRaw('COALESCE(SUM(pallet_base_order_items.qty), 0) > order_items.qty')
            ->limit(20)
            ->pluck('id');

        if ($violations->isNotEmpty()) {
            throw new RuntimeException(
                'No se puede crear la guarda de asignación agregada: order_items sobreasignados ' .
                $violations->implode(', ')
            );
        }

        if ($driver === 'pgsql') {
            $this->addPostgresGuards();
        } else {
            $this->addSqliteGuards();
        }
    }

    public function down(): void
    {
        $driver = DB::getDriverName();

        if ($driver === 'pgsql') {
            DB::statement('DROP TRIGGER IF EXISTS "trg_pboi_assigned_le_ordered" ON "pallet_base_order_items"');
            DB::statement('DROP TRIGGER IF EXISTS "trg_order_items_qty_ge_assigned" ON "order_items"');
            DB::statement('DROP FUNCTION IF EXISTS "pallet_guard_assigned_not_exceeding_ordered"');
            DB::statement('DROP FUNCTION IF EXISTS "pallet_guard_order_qty_not_below_assigned"');
        } elseif ($driver === 'sqlite') {
            DB::statement('DROP TRIGGER IF EXISTS "trg_pboi_assigned_le_ordered_insert"');
            DB::statement('DROP TRIGGER IF EXISTS "trg_pboi_assigned_le_ordered_update"');
            DB::statement('DROP TRIGGER IF EXISTS "trg_order_items_qty_ge_assigned"');
        }
    }

    private function addPostgresGuards(): void
    {
        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION "pallet_guard_assigned_not_exceeding_ordered"()
            RETURNS trigger AS $$
            DECLARE
                v_qty integer;
                v_sum bigint;
            BEGIN
                SELECT qty INTO v_qty FROM order_items WHERE id = NEW.order_item_id FOR UPDATE;
                SELECT COALESCE(SUM(qty), 0) INTO v_sum FROM pallet_base_order_items WHERE order_item_id = NEW.order_item_id;
                IF v_sum > v_qty THEN
                    RAISE EXCEPTION 'assigned_exceeds_ordered: order_item %', NEW.order_item_id USING ERRCODE = '23514';
                END IF;
                RETURN NULL;
            END;
            $$ LANGUAGE plpgsql
        SQL);

        DB::statement(<<<'SQL'
            CREATE TRIGGER "trg_pboi_assigned_le_ordered"
            AFTER INSERT OR UPDATE OF qty, order_item_id ON "pallet_base_order_items"
            FOR EACH ROW EXECUTE FUNCTION "pallet_guard_assigned_not_exceeding_ordered"()
        SQL);

        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION "pallet_guard_order_qty_not_below_assigned"()
            RETURNS trigger AS $$
            DECLARE
                v_qty integer;
                v_sum bigint;
            BEGIN
                SELECT qty INTO v_qty FROM order_items WHERE id = NEW.id FOR UPDATE;
                SELECT COALESCE(SUM(qty), 0) INTO v_sum FROM pallet_base_order_items WHERE order_item_id = NEW.id;
                IF v_sum > v_qty THEN
                    RAISE EXCEPTION 'assigned_exceeds_ordered: order_item %', NEW.id USING ERRCODE = '23514';
                END IF;
                RETURN NULL;
            END;
            $$ LANGUAGE plpgsql
        SQL);

        DB::statement(<<<'SQL'
            CREATE TRIGGER "trg_order_items_qty_ge_assigned"
            AFTER UPDATE OF qty ON "order_items"
            FOR EACH ROW EXECUTE FUNCTION "pallet_guard_order_qty_not_below_assigned"()
        SQL);
    }

    private function addSqliteGuards(): void
    {
        $sumPboi = '(SELECT COALESCE(SUM(qty), 0) FROM pallet_base_order_items WHERE order_item_id = NEW.order_item_id)';
        $qtyItem = '(SELECT qty FROM order_items WHERE id = NEW.order_item_id)';

        DB::statement(
            'CREATE TRIGGER "trg_pboi_assigned_le_ordered_insert" ' .
            'AFTER INSERT ON "pallet_base_order_items" FOR EACH ROW ' .
            "WHEN {$sumPboi} > {$qtyItem} BEGIN " .
            "SELECT RAISE(ABORT, 'assigned_exceeds_ordered'); END"
        );

        DB::statement(
            'CREATE TRIGGER "trg_pboi_assigned_le_ordered_update" ' .
            'AFTER UPDATE OF qty, order_item_id ON "pallet_base_order_items" FOR EACH ROW ' .
            "WHEN {$sumPboi} > {$qtyItem} BEGIN " .
            "SELECT RAISE(ABORT, 'assigned_exceeds_ordered'); END"
        );

        DB::statement(
            'CREATE TRIGGER "trg_order_items_qty_ge_assigned" ' .
            'AFTER UPDATE OF qty ON "order_items" FOR EACH ROW ' .
            'WHEN NEW.qty < (SELECT COALESCE(SUM(qty), 0) FROM pallet_base_order_items WHERE order_item_id = NEW.id) BEGIN ' .
            "SELECT RAISE(ABORT, 'assigned_exceeds_ordered'); END"
        );
    }
};
