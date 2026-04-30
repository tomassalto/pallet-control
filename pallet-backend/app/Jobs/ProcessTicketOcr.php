<?php

namespace App\Jobs;

use App\Models\OrderTicketPhoto;
use App\Services\TicketOcrService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;

class ProcessTicketOcr implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public function __construct(public int $photoId) {}

    public function handle(TicketOcrService $service): void
    {
        $photo = OrderTicketPhoto::find($this->photoId);
        if (! $photo) {
            Log::warning("ProcessTicketOcr: foto {$this->photoId} no encontrada.");
            return;
        }

        $service->processPhoto($photo);
    }
}
