<?php

namespace App\Jobs;

use App\Services\StorageCleanupService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;
use Throwable;

class DeleteStoredFile implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 3;

    /** @var array<int, int> */
    public array $backoff = [30, 120];

    public int $timeout = 60;

    public function __construct(public int $intentId) {}

    public function handle(StorageCleanupService $service): void
    {
        $service->process($this->intentId);
    }

    public function failed(Throwable $e): void
    {
        // La intención queda pendiente: la recupera el barrido
        // storage:retry-pending-deletions.
        Log::error('DeleteStoredFile agotó sus intentos de cola', [
            'intent_id' => $this->intentId,
            'error' => $e::class,
        ]);
    }
}
