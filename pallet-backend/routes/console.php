<?php

use App\Models\OrderTicketPhoto;
use App\Services\TicketOcrService;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Comando exclusivo del gate de concurrencia (APP_ENV=testing): invoca
// el camino real de escritura del OCR fuera de HTTP para acreditar la
// carrera contra la finalización.
if (app()->environment('testing')) {
    Artisan::command('test:ocr-persist {photoId} {--result}', function () {
        $photo = OrderTicketPhoto::find($this->argument('photoId'));
        if (! $photo) {
            return 1;
        }
        $service = app(TicketOcrService::class);
        $written = $service->writePhotoWhileOrderOpen($photo, function ($locked) {
            if ($this->option('result')) {
                $locked->update([
                    'ocr_data' => ['eans' => []],
                    'ocr_processed_at' => now(),
                ]);
            } else {
                $current = $locked->getRawOriginal('ocr_log') ?? '';
                $locked->ocr_log = $current === '' ? 'gate-line' : $current . "\ngate-line";
                $locked->saveQuietly();
            }
        });
        $this->info($written ? 'written' : 'discarded');

        return $written ? 0 : 1;
    });
}
