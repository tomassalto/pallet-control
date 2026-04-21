<?php

namespace App\Helpers;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class TelegramNotifier
{
    /**
     * Envía un mensaje al chat/grupo de Telegram configurado.
     * Si no está configurado o falla, silencio absoluto.
     */
    public static function send(string $message): void
    {
        $token  = config('services.telegram.token');
        $chatId = config('services.telegram.chat_id');

        if (empty($token) || empty($chatId)) {
            return;
        }

        try {
            Http::timeout(8)->post(
                "https://api.telegram.org/bot{$token}/sendMessage",
                [
                    'chat_id'    => $chatId,
                    'text'       => $message,
                    'parse_mode' => 'Markdown',
                ]
            );
        } catch (\Throwable $e) {
            Log::warning('[Telegram] Notification failed: ' . $e->getMessage());
        }
    }
}
