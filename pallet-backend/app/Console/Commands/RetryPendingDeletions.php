<?php

namespace App\Console\Commands;

use App\Services\StorageCleanupService;
use Illuminate\Console\Command;

class RetryPendingDeletions extends Command
{
    protected $signature = 'storage:retry-pending-deletions
                            {--limit=100 : Máximo de intenciones procesadas por pasada}
                            {--include-exhausted : Reprocesa también intenciones agotadas}
                            {--watch : Repite el barrido en bucle}
                            {--interval=600 : Segundos entre pasadas en modo watch}';

    protected $description = 'Reintenta los borrados pendientes de objetos en storage.';

    public function handle(StorageCleanupService $cleanup): int
    {
        $limit = (int) $this->option('limit');
        $includeExhausted = (bool) $this->option('include-exhausted');
        $interval = max(1, (int) $this->option('interval'));

        do {
            $counts = $cleanup->retryPending($limit, $includeExhausted);

            $this->line(sprintf(
                'procesadas=%d completadas=%d fallidas=%d omitidas=%d agotadas_pendientes=%d',
                $counts['processed'],
                $counts['completed'],
                $counts['failed'],
                $counts['skipped'],
                $counts['exhausted_remaining'],
            ));

            if (! $this->option('watch')) {
                return $counts['failed'] > 0 ? self::FAILURE : self::SUCCESS;
            }

            sleep($interval);
        } while (true);
    }
}
