<?php

namespace App\Jobs;

use App\Models\OrderTicketPhoto;
use App\Services\TicketOcrService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;
use Throwable;

class ProcessTicketOcr implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    // Dos intentos: el OCR consume requests pagas al proveedor, no se
    // reintenta indefinidamente. Timeout acotado: un documento grande
    // no puede quedar ejecutando más que la reserva de la cola.
    public int $tries = 2;

    /** @var array<int, int> */
    public array $backoff = [30];

    public int $timeout = 110;

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

    public function failed(Throwable $e): void
    {
        // Sólo la clase de la excepción: el mensaje puede exponer datos
        // del proveedor o del documento.
        Log::error('ProcessTicketOcr agotó sus intentos de cola', [
            'photo_id' => $this->photoId,
            'error' => $e::class,
        ]);
    }
}
