<?php

namespace App\Console\Commands;

use App\Services\StorageCleanupService;
use Illuminate\Console\Command;

class StorageCleanupStatus extends Command
{
    protected $signature = 'storage:cleanup-status
                            {--alert-after= : Minutos de antigüedad para alertar pendientes (por defecto config)}';

    protected $description = 'Informa el estado operativo de las intenciones de borrado de objetos.';

    public function handle(StorageCleanupService $cleanup): int
    {
        $alertAfter = $this->option('alert-after') !== null
            ? (int) $this->option('alert-after')
            : (int) config('queue.storage_cleanup_alert_minutes', 60);

        $status = $cleanup->status($alertAfter);

        $this->table(
            ['Métrica', 'Valor'],
            [
                ['Pendientes', $status['pending']],
                ['Antigüedad máxima pendiente (min)', $status['oldest_pending_minutes'] ?? '-'],
                ['Agotadas', $status['exhausted']],
                ['Completadas últimas 24h', $status['completed_last_24h']],
                ['Jobs de borrado en failed_jobs', $status['failed_jobs']],
                ['Procesando (lease activo)', $status['processing']],
                ['Leases vencidos', $status['expired_leases']],
                ['Completadas retenidas', $status['completed_retained']],
                ['Purgables por retención', $status['purgeable']],
                ['Retención configurada (días)', $status['retention_days']],
                ['Umbral de alerta (min)', $alertAfter],
                ['Alerta', $status['alert'] ? 'SÍ' : 'no'],
            ],
        );

        if ($status['alert']) {
            $this->error('Alerta activa: revisar worker y barrido (ver D-019, recuperación operativa).');

            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
