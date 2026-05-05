<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class ResetForProduction extends Command
{
    protected $signature   = 'app:reset {--confirm : Confirmar que querés borrar todo}';
    protected $description = 'Limpia todos los datos operativos manteniendo la tabla products intacta.';

    // Orden correcto para respetar FK constraints
    private array $tables = [
        'photo_annotations',
        'pallet_base_order_items',
        'pallet_base_photos',
        'order_ticket_photos',
        'order_tickets',
        'pallet_photos',
        'pallet_bases',
        'order_items',
        'movements',
        'pending_items',
        'activity_logs',
        'order_pallet',
        'pallets',
        'orders',
        'customers',
        'personal_access_tokens',
        'sessions',
        'password_reset_tokens',
        'users',
    ];

    public function handle(): int
    {
        if (! $this->option('confirm')) {
            $this->error('Usá --confirm para ejecutar este comando. BORRA TODOS LOS DATOS excepto productos.');
            return 1;
        }

        $this->warn('');
        $this->warn('  ⚠️  RESET DE PRODUCCIÓN');
        $this->warn('  Se borrarán TODOS los datos operativos.');
        $this->warn('  Los productos NO serán eliminados.');
        $this->warn('');

        if (! $this->confirm('¿Estás seguro? Esta acción no se puede deshacer.')) {
            $this->info('Operación cancelada.');
            return 0;
        }

        // ── Borrar archivos de storage ────────────────────────────────────
        $this->info('Borrando archivos de storage...');
        $disk = config('filesystems.default', 'public');

        $dirs = ['pallets', 'orders'];
        foreach ($dirs as $dir) {
            try {
                $files = Storage::disk($disk)->allFiles($dir);
                if (! empty($files)) {
                    Storage::disk($disk)->delete($files);
                    $this->line("  ✓ {$dir}/: " . count($files) . " archivos eliminados");
                } else {
                    $this->line("  - {$dir}/: vacío");
                }
            } catch (\Throwable $e) {
                $this->warn("  ⚠ No se pudo limpiar {$dir}/: " . $e->getMessage());
            }
        }

        // ── Truncar tablas ────────────────────────────────────────────────
        $this->info('Truncando tablas...');
        DB::statement('SET FOREIGN_KEY_CHECKS=0;');

        foreach ($this->tables as $table) {
            try {
                DB::table($table)->truncate();
                $this->line("  ✓ {$table}");
            } catch (\Throwable $e) {
                $this->warn("  ⚠ {$table}: " . $e->getMessage());
            }
        }

        DB::statement('SET FOREIGN_KEY_CHECKS=1;');

        // ── Resultado ─────────────────────────────────────────────────────
        $productCount = DB::table('products')->count();
        $this->info('');
        $this->info("✅ Reset completado. Productos conservados: {$productCount}");
        $this->info('   Registrate ahora para crear la cuenta superadmin.');
        $this->info('');

        return 0;
    }
}
