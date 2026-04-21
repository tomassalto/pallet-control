<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\PhotoUploadService;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class TelegramBotController extends Controller
{
    private const HELP = <<<'TXT'
🤖 *PalletBot — Comandos*

Mandá una foto con este texto en el caption:

📦 *p* — último pallet abierto
📦 *p 2* — segundo pallet más reciente
🗂️ *b* — última base del último pallet
🗂️ *b A1* — base "A1" del último pallet
🗂️ *b 2 A1* — base "A1" del segundo pallet
🎫 *t* — último pedido abierto
🎫 *t 12345* — pedido específico

_Escribí *ayuda* sin foto para ver esto._
TXT;

    // ─────────────────────────────────────────────────────
    // POST /api/v1/telegram/webhook
    // ─────────────────────────────────────────────────────
    public function webhook(Request $request)
    {
        // Validar secret token de Telegram
        $secret = config('services.telegram.webhook_secret');
        if ($secret && $request->header('X-Telegram-Bot-Api-Secret-Token') !== $secret) {
            return response()->json(['error' => 'Unauthorized'], 401);
        }

        $update  = $request->all();
        $message = $update['message'] ?? null;

        if (!$message) {
            return response()->json(['ok' => true]);
        }

        $chatId  = $message['chat']['id'];
        $text    = trim($message['text'] ?? '');
        $photo   = $message['photo'] ?? null;
        $caption = trim($message['caption'] ?? '');

        // Comandos de texto (sin foto)
        $helpTriggers = ['ayuda', '?', '/ayuda', '/help', '/start'];
        if ($text && in_array(strtolower($text), $helpTriggers)) {
            $this->reply($chatId, self::HELP);
            return response()->json(['ok' => true]);
        }

        // Foto con caption
        if ($photo) {
            $this->handlePhoto($chatId, $photo, $caption);
        }

        return response()->json(['ok' => true]);
    }

    // ─────────────────────────────────────────────────────
    // Internals
    // ─────────────────────────────────────────────────────

    private function handlePhoto(int|string $chatId, array $photoSizes, string $caption): void
    {
        if (!$caption) {
            $this->reply($chatId, "❓ Mandaste una foto sin comando.\n\n" . self::HELP);
            return;
        }

        $params = $this->parseCommand($caption);

        if (!$params) {
            $this->reply($chatId, "❓ Comando no reconocido.\n\n" . self::HELP);
            return;
        }

        try {
            // Descargar la foto desde Telegram (el tamaño más grande = último del array)
            $uploadedFile = $this->downloadPhoto(end($photoSizes)['file_id']);

            if (!$uploadedFile) {
                $this->reply($chatId, '❌ No se pudo descargar la foto.');
                return;
            }

            $result = app(PhotoUploadService::class)->upload($uploadedFile, $params);

            $this->reply($chatId, $result['msg'] ?? '✅ Foto guardada');

        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException) {
            $this->reply($chatId, '❌ No encontrado. Verificá el código.');
        } catch (\Throwable $e) {
            Log::error('[TelegramBot] handlePhoto error: ' . $e->getMessage());
            $this->reply($chatId, '❌ Error: ' . $e->getMessage());
        } finally {
            // Limpiar temp file si existe
            if (isset($uploadedFile) && $uploadedFile instanceof UploadedFile) {
                @unlink($uploadedFile->getPathname());
            }
        }
    }

    /**
     * Parsea el caption a un array de parámetros compatible con PhotoUploadService.
     * Mismo formato que el bot de WhatsApp: p, p 2, b A1, b 2 A1, t, t 12345
     */
    private function parseCommand(string $caption): ?array
    {
        $parts = preg_split('/\s+/', strtolower(trim($caption)));
        $cmd   = $parts[0] ?? '';

        $typeMap = [
            'p' => 'pallet', 'pallet' => 'pallet',
            'b' => 'base',   'base'   => 'base',
            't' => 'ticket', 'ticket' => 'ticket',
        ];

        $type = $typeMap[$cmd] ?? null;
        if (!$type) return null;

        $params = ['type' => $type];

        if ($type === 'pallet') {
            $arg = $parts[1] ?? null;
            if (!$arg || preg_match('/^\d{1,2}$/', $arg)) {
                $params['pallet_index'] = (int) ($arg ?? 1);
            } else {
                $params['pallet_code'] = $arg;
            }

        } elseif ($type === 'base') {
            $arg1 = $parts[1] ?? null;
            $rest = implode(' ', array_slice($parts, 2));

            if (!$arg1) {
                $params['pallet_index'] = 1;
            } elseif (preg_match('/^\d{1,2}$/', $arg1) && $rest) {
                $params['pallet_index'] = (int) $arg1;
                $params['base_name']    = $rest;
            } else {
                $params['pallet_index'] = 1;
                $params['base_name']    = implode(' ', array_slice($parts, 1));
            }

        } elseif ($type === 'ticket') {
            $arg = $parts[1] ?? null;
            if (!$arg || preg_match('/^\d{1,2}$/', $arg)) {
                $params['order_index'] = (int) ($arg ?? 1);
            } else {
                $params['order_code'] = $arg;
            }
        }

        return $params;
    }

    /**
     * Descarga una foto de Telegram y la devuelve como UploadedFile temporal.
     */
    private function downloadPhoto(string $fileId): ?UploadedFile
    {
        $token = config('services.telegram.token');

        $meta = Http::get("https://api.telegram.org/bot{$token}/getFile", [
            'file_id' => $fileId,
        ]);

        if (!$meta->ok()) return null;

        $filePath = $meta->json('result.file_path');
        $content  = Http::get("https://api.telegram.org/file/bot{$token}/{$filePath}")->body();

        $tempPath = tempnam(sys_get_temp_dir(), 'tg_') . '.jpg';
        file_put_contents($tempPath, $content);

        return new UploadedFile($tempPath, 'photo.jpg', 'image/jpeg', null, true);
    }

    /**
     * Envía una respuesta de texto al chat de Telegram.
     */
    private function reply(int|string $chatId, string $text): void
    {
        $token = config('services.telegram.token');
        Http::post("https://api.telegram.org/bot{$token}/sendMessage", [
            'chat_id'    => $chatId,
            'text'       => $text,
            'parse_mode' => 'Markdown',
        ]);
    }
}
