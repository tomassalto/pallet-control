<?php

namespace App\Services;

use App\Helpers\ActivityLogger;
use App\Jobs\DeleteStoredFile;
use App\Models\PendingFileDeletion;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use LogicException;
use RuntimeException;
use Throwable;

class StorageCleanupService
{
    public const MAX_ATTEMPTS = 10;

    /**
     * Registra una intención durable de borrado por cada objeto.
     * Debe llamarse dentro de una transacción abierta: la intención
     * nace y muere con la entidad que la motiva.
     *
     * @param iterable<int, string|null> $paths
     */
    public function scheduleDeletion(iterable $paths, string $reason): void
    {
        if (DB::transactionLevel() === 0) {
            throw new LogicException('scheduleDeletion requiere una transacción abierta.');
        }

        $diskName = config('filesystems.default', 'public');

        foreach ($paths as $path) {
            if (! is_string($path) || $path === '') {
                continue;
            }

            $intent = PendingFileDeletion::firstOrCreate(
                ['disk' => $diskName, 'path' => $path],
                ['reason' => $reason, 'available_at' => now()],
            );

            // El job corre tras el commit. Con cola síncrona un fallo de
            // storage lanza dentro del callback post-commit: ya no puede
            // revertir la base, así que se captura y la intención queda
            // pendiente para el barrido (storage:retry-pending-deletions).
            DB::afterCommit(function () use ($intent): void {
                try {
                    DeleteStoredFile::dispatch($intent->id)->afterCommit();
                } catch (Throwable $e) {
                    Log::warning('DeleteStoredFile falló tras commit; la intención queda pendiente', [
                        'intent_id' => $intent->id,
                        'disk' => $intent->disk,
                        'path' => $intent->path,
                        'error' => $e::class,
                    ]);
                }
            });
        }
    }

    /**
     * Procesa una intención. Debe llamarse sin transacción abierta para que
     * cada reintento persista su propio estado. Lanza una RuntimeException
     * saneada —clase del error original e identificador de la intención,
     * sin mensaje ni excepción encadenada— para que la cola reintente sin
     * filtrar mensajes internos a failed_jobs.
     */
    public function process(int $intentId): bool
    {
        $intent = PendingFileDeletion::find($intentId);
        if (! $intent || $intent->completed_at !== null) {
            return true;
        }

        $disk = Storage::disk($intent->disk);
        $error = null;

        try {
            $deleted = $disk->delete($intent->path);
            if ($deleted || ! $disk->exists($intent->path)) {
                $intent->forceFill(['completed_at' => now()])->save();

                return true;
            }

            $error = 'delete_returned_false';
        } catch (Throwable $e) {
            $error = $e::class;
        }

        $this->registrarFallo($intent, $error);

        throw new RuntimeException("Fallo al borrar objeto de storage ({$error}) para la intención {$intent->id}");
    }

    /**
     * Barrido de intenciones vencidas. Un fallo individual no detiene el lote.
     *
     * @return array{processed: int, completed: int, failed: int, exhausted_remaining: int}
     */
    public function retryPending(int $limit, bool $includeExhausted): array
    {
        $intents = PendingFileDeletion::query()
            ->whereNull('completed_at')
            ->where('available_at', '<=', now())
            ->when(! $includeExhausted, fn ($q) => $q->where('attempts', '<', self::MAX_ATTEMPTS))
            ->orderBy('id')
            ->limit($limit)
            ->get();

        // Reprocesar agotadas es una decisión operativa: queda auditada.
        $exhaustedIds = $includeExhausted
            ? $intents->where('attempts', '>=', self::MAX_ATTEMPTS)->pluck('id')->all()
            : [];

        if ($exhaustedIds !== []) {
            ActivityLogger::log(
                action: 'file_deletion_exhausted_retried',
                entityType: 'pending_file_deletion',
                entityId: null,
                description: 'Reprocesamiento manual de intenciones agotadas: ' . count($exhaustedIds),
                newValues: ['intent_ids' => $exhaustedIds],
            );
        }

        $counts = [
            'processed' => 0,
            'completed' => 0,
            'failed' => 0,
            'exhausted_remaining' => 0,
        ];

        foreach ($intents as $intent) {
            $counts['processed']++;
            try {
                if ($this->process($intent->id)) {
                    $counts['completed']++;
                }
            } catch (Throwable) {
                $counts['failed']++;
            }
        }

        $counts['exhausted_remaining'] = PendingFileDeletion::query()
            ->whereNull('completed_at')
            ->where('attempts', '>=', self::MAX_ATTEMPTS)
            ->count();

        if ($counts['exhausted_remaining'] > 0) {
            Log::critical('Hay intenciones de borrado agotadas pendientes de diagnóstico', [
                'exhausted_remaining' => $counts['exhausted_remaining'],
            ]);
        }

        // Intenciones viejas sin agotar sugieren worker o barrido detenidos.
        $alertMinutes = (int) config('queue.storage_cleanup_alert_minutes', 60);
        $staleQuery = PendingFileDeletion::query()
            ->whereNull('completed_at')
            ->where('attempts', '<', self::MAX_ATTEMPTS)
            ->where('created_at', '<', now()->subMinutes($alertMinutes));

        $staleCount = $staleQuery->count();
        if ($staleCount > 0) {
            $oldest = $staleQuery->min('created_at');
            Log::critical('Hay intenciones de borrado pendientes más viejas que el umbral de alerta', [
                'stale_pending' => $staleCount,
                'oldest_pending_minutes' => (int) abs(now()->diffInMinutes($oldest)),
                'alert_minutes' => $alertMinutes,
            ]);
        }

        return $counts;
    }

    /**
     * Estado operativo de la limpieza de storage para storage:cleanup-status.
     *
     * @return array{
     *     pending: int,
     *     oldest_pending_minutes: int|null,
     *     exhausted: int,
     *     completed_last_24h: int,
     *     failed_jobs: int,
     *     alert: bool,
     * }
     */
    public function status(int $alertAfterMinutes): array
    {
        $pendingQuery = PendingFileDeletion::query()
            ->whereNull('completed_at')
            ->where('attempts', '<', self::MAX_ATTEMPTS);

        $pending = $pendingQuery->count();
        $oldest = $pendingQuery->min('created_at');
        $oldestPendingMinutes = $oldest === null
            ? null
            : (int) abs(now()->diffInMinutes($oldest));

        $exhausted = PendingFileDeletion::query()
            ->whereNull('completed_at')
            ->where('attempts', '>=', self::MAX_ATTEMPTS)
            ->count();

        $completedLast24h = PendingFileDeletion::query()
            ->where('completed_at', '>=', now()->subDay())
            ->count();

        $failedJobs = DB::table('failed_jobs')
            ->where('payload', 'like', '%DeleteStoredFile%')
            ->count();

        return [
            'pending' => $pending,
            'oldest_pending_minutes' => $oldestPendingMinutes,
            'exhausted' => $exhausted,
            'completed_last_24h' => $completedLast24h,
            'failed_jobs' => $failedJobs,
            'alert' => $exhausted > 0
                || ($oldestPendingMinutes !== null && $oldestPendingMinutes > $alertAfterMinutes),
        ];
    }

    private function registrarFallo(PendingFileDeletion $intent, string $error): void
    {
        $intent->attempts++;
        $intent->last_error = $error;
        $intent->available_at = now()->addMinutes(min(2 ** $intent->attempts, 60));

        if ($intent->attempts === self::MAX_ATTEMPTS) {
            // Transición a agotada (sólo la primera vez que alcanza el
            // máximo): el cambio de estado y su auditoría confirman juntos
            // o no queda ninguno.
            DB::transaction(function () use ($intent): void {
                $intent->save();

                ActivityLogger::log(
                    action: 'file_deletion_exhausted',
                    entityType: 'pending_file_deletion',
                    entityId: $intent->id,
                    description: "Borrado de objeto agotado tras {$intent->attempts} intentos",
                    newValues: [
                        'disk' => $intent->disk,
                        'path' => $intent->path,
                        'attempts' => $intent->attempts,
                        'last_error' => $intent->last_error,
                    ],
                );
            });
        } else {
            $intent->save();
        }

        $context = [
            'intent_id' => $intent->id,
            'disk' => $intent->disk,
            'path' => $intent->path,
            'attempts' => $intent->attempts,
            'error' => $error,
        ];

        if ($intent->attempts >= self::MAX_ATTEMPTS) {
            Log::critical('Intención de borrado agotada; requiere intervención manual', $context);
        } else {
            Log::warning('Reintento programado para borrado de objeto', $context);
        }
    }
}
