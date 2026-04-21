<?php

namespace App\Helpers;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class WhatsAppNotifier
{
    /**
     * Envía un mensaje al bot de WhatsApp.
     * Si el bot no está configurado o falla, simplemente loguea un warning.
     */
    public static function send(string $message): void
    {
        $url    = config('services.whatsapp_bot.url');
        $secret = config('services.whatsapp_bot.secret', '');

        if (empty($url)) {
            return; // Bot no configurado → silencio
        }

        try {
            Http::withHeaders(['x-api-key' => $secret])
                ->timeout(8)
                ->post(rtrim($url, '/') . '/send', ['message' => $message]);
        } catch (\Throwable $e) {
            Log::warning('[WhatsApp] Notification failed: ' . $e->getMessage());
        }
    }
}
