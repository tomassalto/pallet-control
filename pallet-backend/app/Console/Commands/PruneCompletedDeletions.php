<?php

namespace App\Console\Commands;

use App\Services\StorageCleanupService;
use Illuminate\Console\Command;
use InvalidArgumentException;

class PruneCompletedDeletions extends Command
{
    protected $signature = 'storage:prune-deletions
                            {--days= : Días de retención (por defecto config)}
                            {--dry-run : Informa sin borrar}';

    protected $description = 'Purga intenciones de borrado completadas más viejas que la retención.';

    public function handle(StorageCleanupService $cleanup): int
    {
        $days = $this->option('days') !== null
            ? (int) $this->option('days')
            : (int) config('queue.storage_cleanup_retention_days', 30);
        $dryRun = (bool) $this->option('dry-run');

        try {
            $result = $cleanup->pruneCompleted($days, $dryRun);
        } catch (InvalidArgumentException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->line(sprintf(
            'retencion_dias=%d corte=%s elegibles=%d eliminadas=%d',
            $result['retention_days'],
            $result['cutoff'],
            $result['eligible'],
            $result['deleted'],
        ));

        if ($dryRun) {
            $this->info('Simulacro: no se modificó ninguna fila.');
        }

        return self::SUCCESS;
    }
}
