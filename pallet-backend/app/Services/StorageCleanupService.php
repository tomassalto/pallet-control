<?php

namespace App\Services;

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
     * cada reintento persista su propio estado. Lanza la excepción original
     * (o RuntimeException si delete() devolvió false) para que la cola
     * pueda reintentar.
     */
    public function process(int $intentId): bool
    {
        $intent = PendingFileDeletion::find($intentId);
        if (! $intent || $intent->completed_at !== null) {
            return true;
        }

        $disk = Storage::disk($intent->disk);
        $thrown = null;
        $error = null;

        try {
            $deleted = $disk->delete($intent->path);
            if ($deleted || ! $disk->exists($intent->path)) {
                $intent->forceFill(['completed_at' => now()])->save();

                return true;
            }

            $error = 'delete_returned_false';
        } catch (Throwable $e) {
            $thrown = $e;
            $error = $e::class;
        }

        $this->registrarFallo($intent, $error);

        throw $thrown ?? new RuntimeException("delete() devolvió false y el objeto sigue existiendo: {$intent->path}");
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

        return $counts;
    }

    private function registrarFallo(PendingFileDeletion $intent, string $error): void
    {
        $intent->attempts++;
        $intent->last_error = $error;
        $intent->available_at = now()->addMinutes(min(2 ** $intent->attempts, 60));
        $intent->save();

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
