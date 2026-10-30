<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;

class ResetForProduction extends Command
{
    protected $signature = 'app:reset {--confirm : Confirmar que querés borrar todo}';

    protected $description = 'Limpia todos los datos operativos manteniendo la tabla products intacta.';

    // Orden correcto para respetar FK constraints
    private array $tables = [
        'photo_annotations',
        'pallet_base_order_items',
        'pallet_base_photos',
        'order_ticket_photos',
        'order_tickets',
        'missing_item_requests',
        'pallet_photos',
        'pallet_bases',
        'order_items',
        'pending_items',
        'activity_logs',
        'order_pallet',
        'pallets',
        'orders',
        'customers',
        'personal_access_tokens',
        'sessions',
        'password_reset_tokens',
        'jobs',
        'failed_jobs',
        'job_batches',
        'cache_locks',
        'cache',
        'users',
    ];

    public function handle(): int
    {
        if (! $this->option('confirm')) {
            $this->error('Usá --confirm para ejecutar este comando. BORRA TODOS LOS DATOS excepto productos.');

            return 1;
        }

        $missingTables = array_values(array_filter(
            $this->tables,
            fn (string $table): bool => ! Schema::hasTable($table),
        ));

        if ($missingTables !== []) {
            $this->error('Reset cancelado: faltan tablas esperadas: ' . implode(', ', $missingTables));

            return 1;
        }

        $this->warn('');
        $this->warn('  ⚠️  RESET DE PRODUCCIÓN');
        $this->warn('  Se borrarán TODOS los datos operativos.');
        $this->warn('  Los productos NO serán eliminados.');
        $this->warn('  Ejecutando porque --confirm fue pasado explícitamente...');
        $this->warn('');

        // ── Vaciar tablas ─────────────────────────────────────────────────
        // Las tablas están ordenadas respetando FK constraints (hijos primero),
        // por eso no necesitamos deshabilitar checks — DELETE respeta el orden.
        $this->info('Vaciando tablas...');

        DB::transaction(function (): void {
            foreach ($this->tables as $table) {
                DB::table($table)->delete();
                $this->line("  ✓ {$table}");
            }
        });

        // ── Borrar archivos de storage ────────────────────────────────────
        // Se ejecuta después del commit para no borrar evidencia si falla la DB.
        $this->info('Borrando archivos de storage...');
        $disk = config('filesystems.default', 'public');

        foreach (['pallets', 'orders'] as $dir) {
            $files = Storage::disk($disk)->allFiles($dir);
            if (! empty($files)) {
                Storage::disk($disk)->delete($files);
                $this->line("  ✓ {$dir}/: " . count($files) . ' archivos eliminados');
            } else {
                $this->line("  - {$dir}/: vacío");
            }
        }

        // ── Resultado ─────────────────────────────────────────────────────
        $productCount = DB::table('products')->count();
        $this->info('');
        $this->info("✅ Reset completado. Productos conservados: {$productCount}");
        $this->info('   Registrate ahora para crear la cuenta superadmin.');
        $this->info('');

        return 0;
    }
}
