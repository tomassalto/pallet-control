<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\Pallet;
use App\Services\PhotoUploadService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class TelegramBotController extends Controller
{
    // TTL del file_id cacheado mientras el usuario elige destino
    private const PHOTO_CACHE_TTL = 5; // minutos

    // ─────────────────────────────────────────────────────
    // POST /api/v1/telegram/webhook
    // ─────────────────────────────────────────────────────
    public function webhook(Request $request): JsonResponse
    {
        $secret = config('services.telegram.webhook_secret');
        if ($secret && !hash_equals(
            (string) $secret,
            (string) $request->header('X-Telegram-Bot-Api-Secret-Token', ''),
        )) {
            return response()->json(['error' => 'Unauthorized'], 401);
        }

        $update = $request->all();

        // ── Callback query: usuario apretó un botón inline ──────────
        if (isset($update['callback_query'])) {
            return $this->handleCallbackQuery($update['callback_query']);
        }

        $message = $update['message'] ?? null;
        if (!$message) {
            return response()->json(['ok' => true]);
        }

        $chatId  = $message['chat']['id'];
        $userId  = $message['from']['id'];
        $text    = trim($message['text'] ?? '');
        $photo   = $message['photo'] ?? null;
        $caption = trim($message['caption'] ?? '');

        // ── Comandos de texto ────────────────────────────────────────
        if ($text) {
            $lower = strtolower($text);
            if (in_array($lower, ['/start', '/menu', 'menu'])) {
                $this->sendMainMenu($chatId);
                return response()->json(['ok' => true]);
            }
            if (in_array($lower, ['ayuda', '?', '/ayuda', '/help'])) {
                $this->reply($chatId, $this->helpText());
                return response()->json(['ok' => true]);
            }
        }

        // ── Foto con caption → flujo original (rápido) ──────────────
        if ($photo && $caption) {
            $this->handlePhotoWithCaption($chatId, $photo, $caption);
            return response()->json(['ok' => true]);
        }

        // ── Foto sin caption → flujo interactivo ────────────────────
        if ($photo && !$caption) {
            $this->handlePhotoWithoutCaption($chatId, $userId, $photo);
            return response()->json(['ok' => true]);
        }

        return response()->json(['ok' => true]);
    }

    // ─────────────────────────────────────────────────────
    // Flujo interactivo: foto sin caption
    // ─────────────────────────────────────────────────────

    private function handlePhotoWithoutCaption(
        int|string $chatId,
        int|string $userId,
        array $photoSizes,
    ): void {
        $fileId = end($photoSizes)['file_id'];

        // Guardar el file_id mientras el usuario elige destino
        Cache::put("tg_photo_{$userId}", $fileId, now()->addMinutes(self::PHOTO_CACHE_TTL));

        // Consultar pallets y pedidos abiertos
        $pallets = Pallet::where('status', 'open')
            ->orderByDesc('id')
            ->with(['bases' => fn($q) => $q->orderByDesc('id')->limit(1)])
            ->limit(3)
            ->get();

        $order = Order::where('status', 'open')
            ->orderByDesc('id')
            ->first();

        if ($pallets->isEmpty() && !$order) {
            $this->reply($chatId, '❌ No hay pallets ni pedidos abiertos en este momento.');
            return;
        }

        $buttons = [];

        foreach ($pallets as $pallet) {
            $lastBase = $pallet->bases->first();
            $label = $lastBase
                ? "📦 Base {$lastBase->name} — {$pallet->code}"
                : "📦 {$pallet->code} (sin bases)";

            $buttons[] = [['text' => $label, 'callback_data' => "base:{$pallet->id}"]];
        }

        if ($order) {
            $buttons[] = [['text' => "🧾 Ticket — Pedido #{$order->code}", 'callback_data' => "ticket:{$order->id}"]];
        }

        $buttons[] = [['text' => '❌ Cancelar', 'callback_data' => 'cancel']];

        $this->sendInlineKeyboard(
            $chatId,
            "📸 *Foto recibida*\n¿A dónde la mando?",
            $buttons,
        );
    }

    // ─────────────────────────────────────────────────────
    // Callback query: usuario apretó un botón
    // ─────────────────────────────────────────────────────

    private function handleCallbackQuery(array $callbackQuery): JsonResponse
    {
        $callbackId = $callbackQuery['id'];
        $chatId     = $callbackQuery['message']['chat']['id'];
        $messageId  = $callbackQuery['message']['message_id'];
        $userId     = $callbackQuery['from']['id'];
        $data       = $callbackQuery['data'];

        // Quitar el spinner de Telegram inmediatamente
        $this->answerCallback($callbackId);

        if ($data === 'cancel') {
            Cache::forget("tg_photo_{$userId}");
            $this->editMessage($chatId, $messageId, '❌ Cancelado.');
            return response()->json(['ok' => true]);
        }

        // Recuperar la foto cacheada
        $fileId = Cache::get("tg_photo_{$userId}");
        if (!$fileId) {
            $this->editMessage($chatId, $messageId, '⏱ La sesión expiró. Mandá la foto de nuevo.');
            return response()->json(['ok' => true]);
        }

        $this->editMessage($chatId, $messageId, '⏳ Guardando foto…');

        $uploadedFile = null;
        try {
            $uploadedFile = $this->downloadPhoto($fileId);

            if (!$uploadedFile) {
                $this->editMessage($chatId, $messageId, '❌ No se pudo descargar la foto.');
                return response()->json(['ok' => true]);
            }

            [$type, $id] = explode(':', $data, 2);

            $params = match ($type) {
                'base'   => ['type' => 'base',   'pallet_id' => (int) $id],
                'ticket' => ['type' => 'ticket',  'order_id'  => (int) $id],
                default  => null,
            };

            if (!$params) {
                $this->editMessage($chatId, $messageId, '❌ Acción no reconocida.');
                return response()->json(['ok' => true]);
            }

            $result = app(PhotoUploadService::class)->upload($uploadedFile, $params);

            Cache::forget("tg_photo_{$userId}");
            $this->editMessage($chatId, $messageId, $result['msg'] ?? '✅ Foto guardada');

        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException) {
            $this->editMessage($chatId, $messageId, '❌ No encontrado. Puede que el pallet o pedido haya sido cerrado.');
        } catch (\Throwable $e) {
            Log::error('[TelegramBot] callback error: ' . $e->getMessage());
            $this->editMessage($chatId, $messageId, '❌ Error inesperado: ' . $e->getMessage());
        } finally {
            if ($uploadedFile instanceof UploadedFile) {
                @unlink($uploadedFile->getPathname());
            }
        }

        return response()->json(['ok' => true]);
    }

    // ─────────────────────────────────────────────────────
    // Menú principal (sin foto en contexto)
    // ─────────────────────────────────────────────────────

    private function sendMainMenu(int|string $chatId): void
    {
        $pallet = Pallet::where('status', 'open')->orderByDesc('id')->first();
        $order  = Order::where('status', 'open')->orderByDesc('id')->first();

        $lines = ["🤖 *PalletBot* — Estado actual\n"];

        if ($pallet) {
            $lines[] = "📦 Pallet activo: *{$pallet->code}*";
        } else {
            $lines[] = "📦 Sin pallet abierto";
        }

        if ($order) {
            $lines[] = "🧾 Pedido activo: *#{$order->code}*";
        } else {
            $lines[] = "🧾 Sin pedido abierto";
        }

        $lines[] = "\n_Mandá una foto para subirla al destino que elijas._";
        $lines[] = "_Usá caption p/b/t si querés ir directo._";

        $this->reply($chatId, implode("\n", $lines));
    }

    // ─────────────────────────────────────────────────────
    // Flujo original con caption (backward compat)
    // ─────────────────────────────────────────────────────

    private function handlePhotoWithCaption(
        int|string $chatId,
        array $photoSizes,
        string $caption,
    ): void {
        $params = $this->parseCommand($caption);

        if (!$params) {
            $this->reply($chatId, "❓ Comando no reconocido.\n\n" . $this->helpText());
            return;
        }

        $uploadedFile = null;
        try {
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
            Log::error('[TelegramBot] handlePhotoWithCaption error: ' . $e->getMessage());
            $this->reply($chatId, '❌ Error: ' . $e->getMessage());
        } finally {
            if ($uploadedFile instanceof UploadedFile) {
                @unlink($uploadedFile->getPathname());
            }
        }
    }

    // ─────────────────────────────────────────────────────
    // Helpers de Telegram API
    // ─────────────────────────────────────────────────────

    private function sendInlineKeyboard(int|string $chatId, string $text, array $buttons): void
    {
        $token = config('services.telegram.token');
        Http::post("https://api.telegram.org/bot{$token}/sendMessage", [
            'chat_id'      => $chatId,
            'text'         => $text,
            'parse_mode'   => 'Markdown',
            'reply_markup' => json_encode(['inline_keyboard' => $buttons]),
        ]);
    }

    private function editMessage(int|string $chatId, int $messageId, string $text): void
    {
        $token = config('services.telegram.token');
        Http::post("https://api.telegram.org/bot{$token}/editMessageText", [
            'chat_id'    => $chatId,
            'message_id' => $messageId,
            'text'       => $text,
            'parse_mode' => 'Markdown',
        ]);
    }

    private function answerCallback(string $callbackQueryId, string $text = ''): void
    {
        $token = config('services.telegram.token');
        Http::post("https://api.telegram.org/bot{$token}/answerCallbackQuery", [
            'callback_query_id' => $callbackQueryId,
            'text'              => $text,
        ]);
    }

    private function reply(int|string $chatId, string $text): void
    {
        $token = config('services.telegram.token');
        Http::post("https://api.telegram.org/bot{$token}/sendMessage", [
            'chat_id'    => $chatId,
            'text'       => $text,
            'parse_mode' => 'Markdown',
        ]);
    }

    // ─────────────────────────────────────────────────────
    // Utilidades internas
    // ─────────────────────────────────────────────────────

    /**
     * Parsea el caption a parámetros para PhotoUploadService.
     * Formato: p, p 2, b A1, b 2 A1, t, t 12345
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

    private function helpText(): string
    {
        return <<<'TXT'
🤖 *PalletBot — Comandos rápidos*

Mandá una foto _sin caption_ para elegir el destino con botones.

O usá caption para ir directo:

📦 *p* — último pallet abierto
🗂️ *b* — última base del último pallet
🗂️ *b A1* — base "A1" del último pallet
🎫 *t* — último pedido abierto
🎫 *t 12345* — pedido específico

_Escribí */menu* para ver el estado actual._
TXT;
    }
}
