<?php

namespace App\Services;

use App\Helpers\ActivityLogger;
use App\Jobs\DeleteStoredFile;
use App\Models\PendingFileDeletion;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use InvalidArgumentException;
use LogicException;
use RuntimeException;
use Throwable;

class StorageCleanupService
{
    public const MAX_ATTEMPTS = 10;

    // Mayor que el timeout de DeleteStoredFile (60s) y menor que el
    // intervalo del barrido (600s): si el worker muere con el lease
    // tomado, el siguiente barrido ya puede reclamar la intención.
    public const LEASE_SECONDS = 300;

    // Mínimo de retención de completadas: garantiza que la métrica
    // completed_last_24h nunca se ve afectada por una purga.
    public const MIN_RETENTION_DAYS = 2;

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
    public function process(int $intentId, bool $includeExhausted = false, bool $onlyDue = false): bool
    {
        $intent = PendingFileDeletion::find($intentId);
        // Pudo ser purgada tras completarse: el trabajo ya no existe.
        if (! $intent || $intent->completed_at !== null) {
            return true;
        }

        // Reclamo con un solo UPDATE condicional: quien afecta la fila
        // es el dueño del lease; los demás se retiran sin tocar storage.
        $token = (string) Str::uuid();
        $now = now();
        $claimed = PendingFileDeletion::query()
            ->where('id', $intentId)
            ->whereNull('completed_at')
            ->where(fn ($q) => $q->whereNull('claimed_until')->orWhere('claimed_until', '<=', $now))
            ->when(! $includeExhausted, fn ($q) => $q->where('attempts', '<', self::MAX_ATTEMPTS))
            ->when($onlyDue, fn ($q) => $q->where('available_at', '<=', $now))
            ->update([
                'claim_token' => $token,
                'claimed_until' => $now->copy()->addSeconds(self::LEASE_SECONDS),
                'updated_at' => $now,
            ]);

        if ($claimed !== 1) {
            // Otro worker tiene un lease activo o la intención no es
            // elegible (agotada sin flag, aún en backoff): no es error.
            return false;
        }

        if ($intent->claimed_until !== null) {
            Log::warning('Intención de borrado reclamada tras lease vencido', [
                'intent_id' => $intent->id,
                'claimed_until' => Carbon::parse($intent->claimed_until)->toIso8601String(),
            ]);
        }

        $error = null;

        try {
            // Un disco desconocido también cuenta como fallo del intento.
            $disk = Storage::disk($intent->disk);
            $deleted = $disk->delete($intent->path);
            if ($deleted || ! $disk->exists($intent->path)) {
                // La completitud es un hecho, no depende del token: si un
                // worker perdió su lease y aun así borró, su UPDATE final
                // es no-op para el nuevo dueño.
                PendingFileDeletion::query()
                    ->where('id', $intentId)
                    ->whereNull('completed_at')
                    ->update([
                        'completed_at' => now(),
                        'claim_token' => null,
                        'claimed_until' => null,
                        'updated_at' => now(),
                    ]);

                return true;
            }

            $error = 'delete_returned_false';
        } catch (Throwable $e) {
            $error = $e::class;
        }

        $this->registrarFallo($intentId, $token, $error);

        throw new RuntimeException("Fallo al borrar objeto de storage ({$error}) para la intención {$intentId}");
    }

    /**
     * Barrido de intenciones vencidas. Un fallo individual no detiene el lote.
     *
     * @return array{processed: int, completed: int, failed: int, skipped: int, exhausted_remaining: int}
     */
    public function retryPending(int $limit, bool $includeExhausted): array
    {
        $intents = PendingFileDeletion::query()
            ->whereNull('completed_at')
            ->where('available_at', '<=', now())
            // Un lease activo significa que otro worker la está procesando.
            ->where(fn ($q) => $q->whereNull('claimed_until')->orWhere('claimed_until', '<=', now()))
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
            'skipped' => 0,
            'exhausted_remaining' => 0,
        ];

        foreach ($intents as $intent) {
            $counts['processed']++;
            try {
                if ($this->process($intent->id, $includeExhausted, onlyDue: true)) {
                    $counts['completed']++;
                } else {
                    // Otro worker se adelantó con el reclamo.
                    $counts['skipped']++;
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
     * Purga intenciones completadas más viejas que la retención. Pendientes,
     * reintentando, procesando y agotadas tienen completed_at nulo, así que
     * por construcción nunca entran a la purga.
     *
     * @return array{retention_days: int, cutoff: string, eligible: int, deleted: int}
     */
    public function pruneCompleted(int $retentionDays, bool $dryRun): array
    {
        if ($retentionDays < self::MIN_RETENTION_DAYS) {
            throw new InvalidArgumentException(
                'La retención mínima es de ' . self::MIN_RETENTION_DAYS . ' días.'
            );
        }

        $cutoff = now()->subDays($retentionDays);
        $eligible = PendingFileDeletion::query()
            ->whereNotNull('completed_at')
            ->where('completed_at', '<', $cutoff)
            ->count();

        $deleted = 0;
        if (! $dryRun) {
            $deleted = DB::transaction(function () use ($cutoff, $retentionDays): int {
                // La condición se reevalúa dentro de la transacción: lo que
                // se complete entre el conteo y el DELETE también se purga.
                $deleted = PendingFileDeletion::query()
                    ->whereNotNull('completed_at')
                    ->where('completed_at', '<', $cutoff)
                    ->delete();

                // Auditoría sin paths ni discos: la purga siempre queda
                // registrada, incluso con cero filas borradas.
                ActivityLogger::log(
                    action: 'file_deletions_pruned',
                    entityType: 'pending_file_deletion',
                    entityId: null,
                    description: "Purga de intenciones completadas anteriores a {$cutoff->toIso8601String()}: {$deleted}",
                    newValues: [
                        'deleted' => $deleted,
                        'cutoff' => $cutoff->toIso8601String(),
                        'retention_days' => $retentionDays,
                    ],
                );

                return $deleted;
            });
        }

        return [
            'retention_days' => $retentionDays,
            'cutoff' => $cutoff->toIso8601String(),
            'eligible' => $eligible,
            'deleted' => $deleted,
        ];
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
     *     processing: int,
     *     expired_leases: int,
     *     completed_retained: int,
     *     purgeable: int,
     *     retention_days: int,
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

        $processing = PendingFileDeletion::query()
            ->whereNull('completed_at')
            ->where('claimed_until', '>', now())
            ->count();

        $expiredLeases = PendingFileDeletion::query()
            ->whereNull('completed_at')
            ->whereNotNull('claimed_until')
            ->where('claimed_until', '<=', now())
            ->count();

        $completedRetained = PendingFileDeletion::query()
            ->whereNotNull('completed_at')
            ->count();

        $retentionDays = (int) config('queue.storage_cleanup_retention_days', 30);
        $purgeable = PendingFileDeletion::query()
            ->whereNotNull('completed_at')
            ->where('completed_at', '<', now()->subDays($retentionDays))
            ->count();

        return [
            'pending' => $pending,
            'oldest_pending_minutes' => $oldestPendingMinutes,
            'exhausted' => $exhausted,
            'completed_last_24h' => $completedLast24h,
            'failed_jobs' => $failedJobs,
            'processing' => $processing,
            'expired_leases' => $expiredLeases,
            'completed_retained' => $completedRetained,
            'purgeable' => $purgeable,
            'retention_days' => $retentionDays,
            'alert' => $exhausted > 0
                || ($oldestPendingMinutes !== null && $oldestPendingMinutes > $alertAfterMinutes),
        ];
    }

    private function registrarFallo(int $intentId, string $token, string $error): void
    {
        $intent = DB::transaction(function () use ($intentId, $token, $error): ?PendingFileDeletion {
            $intent = PendingFileDeletion::query()->lockForUpdate()->find($intentId);
            if (! $intent || $intent->completed_at !== null) {
                return null;
            }

            // Un intento real ocurrió y siempre cuenta, aunque nuestro
            // lease haya vencido y otro worker sea ahora el dueño.
            $intent->attempts++;
            $intent->last_error = $error;

            if ($intent->claim_token === $token) {
                // Sigue siendo nuestro lease: reprogramamos y lo liberamos.
                $intent->available_at = now()->addMinutes(min(2 ** $intent->attempts, 60));
                $intent->claim_token = null;
                $intent->claimed_until = null;
            }
            // Si el token difiere, otro worker la posee: su reclamo y su
            // available_at quedan intactos.

            // Transición a agotada (sólo la primera vez que alcanza el
            // máximo): el cambio de estado y su auditoría confirman juntos
            // o no queda ninguno.
            if ($intent->attempts === self::MAX_ATTEMPTS) {
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
            } else {
                $intent->save();
            }

            return $intent;
        });

        if (! $intent) {
            return;
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
