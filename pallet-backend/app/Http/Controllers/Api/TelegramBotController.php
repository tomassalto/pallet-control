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
            if ($lower === '/vermas' || $lower === 'vermas') {
                $this->sendRecentItems($chatId);
                return response()->json(['ok' => true]);
            }
        }

        // ── Foto con caption → flujo original rápido ─────────────────
        if ($photo && $caption) {
            $this->handlePhotoWithCaption($chatId, $photo, $caption);
            return response()->json(['ok' => true]);
        }

        // ── Foto sin caption → flujo interactivo ─────────────────────
        if ($photo && !$caption) {
            $this->handlePhotoWithoutCaption($chatId, $userId, $photo);
            return response()->json(['ok' => true]);
        }

        return response()->json(['ok' => true]);
    }

    // ─────────────────────────────────────────────────────
    // PASO 1 — Foto sin caption: mostrar pallets y pedidos
    // ─────────────────────────────────────────────────────

    private function handlePhotoWithoutCaption(
        int|string $chatId,
        int|string $userId,
        array $photoSizes,
    ): void {
        $fileId = end($photoSizes)['file_id'];
        Cache::put("tg_photo_{$userId}", $fileId, now()->addMinutes(self::PHOTO_CACHE_TTL));

        $pallets = Pallet::where('status', 'open')
            ->orderByDesc('id')
            ->with([
                'bases'         => fn($q) => $q->orderBy('id'),
                'bases.orderItems',   // para sumar unidades en pallet
                'orders',
                'orders.items',       // para sumar unidades totales del pedido
            ])
            ->limit(4)
            ->get();

        $orders = Order::where('status', 'open')
            ->orderByDesc('id')
            ->with('customer')
            ->limit(3)
            ->get();

        if ($pallets->isEmpty() && $orders->isEmpty()) {
            $this->reply($chatId, '❌ No hay pallets ni pedidos abiertos en este momento.');
            return;
        }

        $numberEmojis = ['1️⃣', '2️⃣', '3️⃣', '4️⃣'];
        $lines   = ["📸 *Foto recibida* — ¿A dónde la mando?\n"];
        $buttons = [];

        foreach ($pallets as $i => $pallet) {
            $num       = $numberEmojis[$i] ?? (($i + 1) . '.');
            $baseCount = $pallet->bases->count();
            $baseSuffix = match (true) {
                $baseCount === 0 => 'sin bases',
                $baseCount === 1 => '1 base',
                default          => "{$baseCount} bases",
            };

            // Unidades organizadas en este pallet
            $qtyInPallet = $pallet->bases
                ->flatMap(fn($b) => $b->orderItems)
                ->sum(fn($item) => $item->pivot->qty ?? 0);

            // Unidades totales de los pedidos asociados
            $qtyOrdered = $pallet->orders
                ->flatMap(fn($o) => $o->items)
                ->sum('qty');

            // Códigos de pedido
            $orderCodes = $pallet->orders->pluck('code')
                ->map(fn($c) => "#{$c}")
                ->join(', ');

            $lines[] = "{$num} *{$pallet->code}* · {$baseSuffix}";

            if ($orderCodes) {
                $uLabel  = $qtyOrdered > 0
                    ? "{$qtyInPallet}/{$qtyOrdered} u."
                    : "{$qtyInPallet} u.";
                $lines[] = "   🧾 {$orderCodes} · {$uLabel}";
            } else {
                $lines[] = "   Sin pedidos asignados";
            }

            $lines[] = "   ⏱ " . $this->relativeTime($pallet->updated_at);
            $lines[] = '';

            $buttons[] = [['text' => "{$num} {$pallet->code}", 'callback_data' => "sel_p:{$pallet->id}"]];
        }

        foreach ($orders as $order) {
            $customerName = $order->customer?->name;
            $label = $customerName
                ? "🧾 Ticket #{$order->code} · {$customerName}"
                : "🧾 Ticket — Pedido #{$order->code}";
            $buttons[] = [['text' => $label, 'callback_data' => "sel_t:{$order->id}"]];
        }

        $buttons[] = [['text' => '❌ Cancelar', 'callback_data' => 'cancel']];

        $this->sendInlineKeyboard($chatId, implode("\n", $lines), $buttons);
    }

    /** Formatea un timestamp como "hoy HH:MM", "ayer HH:MM" o "DD/MM HH:MM" */
    private function relativeTime(\Carbon\Carbon $dt): string
    {
        $local = $dt->copy()->timezone('America/Argentina/Buenos_Aires');
        if ($local->isToday())     return 'hoy ' . $local->format('H:i');
        if ($local->isYesterday()) return 'ayer ' . $local->format('H:i');
        return $local->format('d/m H:i');
    }

    // ─────────────────────────────────────────────────────
    // Dispatcher de callback queries
    // ─────────────────────────────────────────────────────

    private function handleCallbackQuery(array $callbackQuery): JsonResponse
    {
        $callbackId = $callbackQuery['id'];
        $chatId     = $callbackQuery['message']['chat']['id'];
        $messageId  = $callbackQuery['message']['message_id'];
        $userId     = $callbackQuery['from']['id'];
        $data       = $callbackQuery['data'];

        $this->answerCallback($callbackId);

        if ($data === 'cancel') {
            Cache::forget("tg_photo_{$userId}");
            $this->editMessage($chatId, $messageId, '❌ Cancelado.');
            return response()->json(['ok' => true]);
        }

        $parts  = explode(':', $data, 2);
        $prefix = $parts[0];
        $id     = isset($parts[1]) ? (int) $parts[1] : null;

        return match ($prefix) {
            'sel_p'    => $this->handleSelectPallet($chatId, $messageId, $userId, $id),
            'sel_t'    => $this->handleUploadTicket($chatId, $messageId, $userId, $id),
            'base'     => $this->handleUploadToBase($chatId, $messageId, $userId, $id),
            'new_base' => $this->handleCreateBaseAndUpload($chatId, $messageId, $userId, $id),
            default    => $this->unknownCallback($chatId, $messageId),
        };
    }

    // ─────────────────────────────────────────────────────
    // PASO 2 — Pallet seleccionado: mostrar sus bases
    // ─────────────────────────────────────────────────────

    private function handleSelectPallet(
        int|string $chatId,
        int        $messageId,
        int|string $userId,
        ?int       $palletId,
    ): JsonResponse {
        $fileId = Cache::get("tg_photo_{$userId}");
        if (!$fileId) {
            $this->editMessage($chatId, $messageId, '⏱ La sesión expiró. Mandá la foto de nuevo.');
            return response()->json(['ok' => true]);
        }

        try {
            $pallet = Pallet::with(['bases' => fn($q) => $q->orderBy('id'), 'orders.customer'])
                ->findOrFail($palletId);
        } catch (\Throwable) {
            $this->editMessage($chatId, $messageId, '❌ Pallet no encontrado.');
            return response()->json(['ok' => true]);
        }

        $customerLabel = $this->palletCustomerLabel($pallet);
        $header = $customerLabel
            ? "📦 *{$pallet->code}* · {$customerLabel}\n¿A qué base mando la foto?"
            : "📦 *{$pallet->code}*\n¿A qué base mando la foto?";

        $bases   = $pallet->bases;
        $nextNum = $bases->count() + 1;
        $buttons = [];

        // Bases existentes (de a 2 por fila)
        foreach ($bases->chunk(2) as $pair) {
            $row = [];
            foreach ($pair as $base) {
                $row[] = ['text' => "🗂 {$base->name}", 'callback_data' => "base:{$base->id}"];
            }
            $buttons[] = $row;
        }

        // Siempre ofrecer crear la siguiente base
        $buttons[] = [['text' => "➕ Base {$nextNum} (nueva)", 'callback_data' => "new_base:{$pallet->id}"]];
        $buttons[] = [['text' => '❌ Cancelar', 'callback_data' => 'cancel']];

        $this->editMessageWithKeyboard($chatId, $messageId, $header, $buttons);
        return response()->json(['ok' => true]);
    }

    // ─────────────────────────────────────────────────────
    // PASO 3a — Base existente seleccionada: subir foto
    // ─────────────────────────────────────────────────────

    private function handleUploadToBase(
        int|string $chatId,
        int        $messageId,
        int|string $userId,
        ?int       $baseId,
    ): JsonResponse {
        return $this->runUpload(
            chatId: $chatId, messageId: $messageId, userId: $userId,
            params: ['type' => 'base', 'base_id' => $baseId],
        );
    }

    // ─────────────────────────────────────────────────────
    // PASO 3b — Crear nueva base y subir foto
    // ─────────────────────────────────────────────────────

    private function handleCreateBaseAndUpload(
        int|string $chatId,
        int        $messageId,
        int|string $userId,
        ?int       $palletId,
    ): JsonResponse {
        $fileId = Cache::get("tg_photo_{$userId}");
        if (!$fileId) {
            $this->editMessage($chatId, $messageId, '⏱ La sesión expiró. Mandá la foto de nuevo.');
            return response()->json(['ok' => true]);
        }

        $this->editMessage($chatId, $messageId, '⏳ Creando base y guardando foto…');

        $uploadedFile = null;
        try {
            $pallet  = Pallet::with(['bases', 'orders.customer'])->findOrFail($palletId);
            $nextNum = $pallet->bases->count() + 1;
            $base    = $pallet->bases()->create(['name' => "Base {$nextNum}"]);

            $uploadedFile = $this->downloadPhoto($fileId);
            if (!$uploadedFile) {
                $this->editMessage($chatId, $messageId, '❌ No se pudo descargar la foto.');
                return response()->json(['ok' => true]);
            }

            app(PhotoUploadService::class)->upload($uploadedFile, [
                'type'    => 'base',
                'base_id' => $base->id,
            ]);

            Cache::forget("tg_photo_{$userId}");

            $customerLabel = $this->palletCustomerLabel($pallet);
            $msg = "✅ *Base {$nextNum}* creada — foto guardada\n📦 *{$pallet->code}*";
            if ($customerLabel) {
                $msg .= "\n👤 {$customerLabel}";
            }
            $this->editMessage($chatId, $messageId, $msg);

        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException) {
            $this->editMessage($chatId, $messageId, '❌ Pallet no encontrado.');
        } catch (\Throwable $e) {
            Log::error('[TelegramBot] createBaseAndUpload: ' . $e->getMessage());
            $this->editMessage($chatId, $messageId, '❌ Error inesperado: ' . $e->getMessage());
        } finally {
            if ($uploadedFile instanceof UploadedFile) {
                @unlink($uploadedFile->getPathname());
            }
        }

        return response()->json(['ok' => true]);
    }

    // ─────────────────────────────────────────────────────
    // Ticket: subir directo al pedido (sin segundo paso)
    // ─────────────────────────────────────────────────────

    private function handleUploadTicket(
        int|string $chatId,
        int        $messageId,
        int|string $userId,
        ?int       $orderId,
    ): JsonResponse {
        return $this->runUpload(
            chatId: $chatId, messageId: $messageId, userId: $userId,
            params: ['type' => 'ticket', 'order_id' => $orderId],
        );
    }

    // ─────────────────────────────────────────────────────
    // Helper genérico: descarga + upload + edición de mensaje
    // ─────────────────────────────────────────────────────

    private function runUpload(
        int|string $chatId,
        int        $messageId,
        int|string $userId,
        array      $params,
    ): JsonResponse {
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

            $result = app(PhotoUploadService::class)->upload($uploadedFile, $params);
            Cache::forget("tg_photo_{$userId}");
            $this->editMessage($chatId, $messageId, $result['msg'] ?? '✅ Foto guardada');

        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException) {
            $this->editMessage($chatId, $messageId, '❌ No encontrado. Puede que el destino haya sido cerrado.');
        } catch (\Throwable $e) {
            Log::error('[TelegramBot] runUpload: ' . $e->getMessage());
            $this->editMessage($chatId, $messageId, '❌ Error inesperado: ' . $e->getMessage());
        } finally {
            if ($uploadedFile instanceof UploadedFile) {
                @unlink($uploadedFile->getPathname());
            }
        }

        return response()->json(['ok' => true]);
    }

    // ─────────────────────────────────────────────────────
    // /menu — estado actual
    // ─────────────────────────────────────────────────────

    private function sendMainMenu(int|string $chatId): void
    {
        $pallets = Pallet::where('status', 'open')
            ->orderByDesc('id')
            ->with(['bases', 'orders.customer'])
            ->limit(3)
            ->get();

        $orders = Order::where('status', 'open')
            ->orderByDesc('id')
            ->with(['customer', 'items'])
            ->limit(3)
            ->get();

        $lines = ["🤖 *PalletBot* — Estado actual\n"];

        if ($pallets->isEmpty()) {
            $lines[] = "📦 Sin pallets abiertos";
        } else {
            foreach ($pallets as $pallet) {
                $customerLabel = $this->palletCustomerLabel($pallet);
                $baseCount     = $pallet->bases->count();
                $info = $customerLabel ? " · {$customerLabel}" : '';
                $info .= ' (' . ($baseCount === 1 ? '1 base' : "{$baseCount} bases") . ')';
                $lines[] = "📦 *{$pallet->code}*{$info}";
            }
        }

        $lines[] = '';

        if ($orders->isEmpty()) {
            $lines[] = "🧾 Sin pedidos abiertos";
        } else {
            foreach ($orders as $order) {
                $customerName = $order->customer?->name ?? '';
                $total = $order->items
                    ->whereNotNull('price')
                    ->sum(fn($i) => $i->qty * $i->price);
                $info = $customerName ? " · {$customerName}" : '';
                if ($total > 0) {
                    $info .= "\n   💰 " . $this->formatPeso($total);
                }
                $lines[] = "🧾 *#{$order->code}*{$info}";
            }
        }

        $lines[] = "\n_Mandá una foto para elegir el destino con botones._";
        $lines[] = "_Usá caption (p / b / t) para ir directo._";

        $this->reply($chatId, implode("\n", $lines));
    }

    // ─────────────────────────────────────────────────────
    // Flujo con caption — backward compat
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
            Log::error('[TelegramBot] handlePhotoWithCaption: ' . $e->getMessage());
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

    private function editMessageWithKeyboard(int|string $chatId, int $messageId, string $text, array $buttons): void
    {
        $token = config('services.telegram.token');
        Http::post("https://api.telegram.org/bot{$token}/editMessageText", [
            'chat_id'      => $chatId,
            'message_id'   => $messageId,
            'text'         => $text,
            'parse_mode'   => 'Markdown',
            'reply_markup' => json_encode(['inline_keyboard' => $buttons]),
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

    private function unknownCallback(int|string $chatId, int $messageId): JsonResponse
    {
        $this->editMessage($chatId, $messageId, '❌ Acción no reconocida.');
        return response()->json(['ok' => true]);
    }

    // ─────────────────────────────────────────────────────
    // Utilidades internas
    // ─────────────────────────────────────────────────────

    /**
     * Nombres de cliente(s) asociados al pallet, separados por ·.
     * Máximo 2 clientes para no saturar el botón.
     */
    private function palletCustomerLabel(Pallet $pallet): string
    {
        return $pallet->orders
            ->map(fn($o) => $o->customer?->name)
            ->filter()
            ->unique()
            ->take(2)
            ->join(' · ');
    }

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

*📋 /vermas* — Ver lista reciente de pallets y pedidos con más detalles

_Escribí */menu* para ver el estado actual._
TXT;
    }

    private function sendRecentItems(int $chatId): void
    {
        $pallets = Pallet::with(['orders.customer', 'bases.orderItems'])
            ->orderByDesc('id')
            ->limit(5)
            ->get();

        $orders = Order::with(['customer', 'pallets', 'items'])
            ->orderByDesc('id')
            ->limit(5)
            ->get();

        $message = "📋 *Lista reciente*\n\n";

        if ($pallets->isEmpty() && $orders->isEmpty()) {
            $message .= "No hay pallets ni pedidos registrados.";
            $this->reply($chatId, $message);
            return;
        }

        $message .= "*PALLETS:*\n";
        foreach ($pallets as $p) {
            $status = $p->status === 'done' ? '✅' : '🔵';
            $customers = $p->orders->pluck('customer.name')->filter()->unique()->join(', ');
            $productCount = $p->bases->flatMap(fn($b) => $b->orderItems ?? [])->count();

            $message .= "{$status} *{$p->code}*\n";
            if ($p->note) {
                $message .= "   📝 {$p->note}\n";
            }
            if ($customers) {
                $message .= "   👤 {$customers}\n";
            }
            $message .= "   📦 {$productCount} productos · {$p->bases->count()} bases\n\n";
        }

        $message .= "*PEDIDOS:*\n";
        foreach ($orders as $o) {
            $status = $o->status === 'done' ? '✅' : ($o->status === 'paused' ? '⏸️' : '🔵');
            $itemsCount = $o->items->count();
            $total = $o->items
                ->whereNotNull('price')
                ->sum(fn($i) => $i->qty * $i->price);

            $message .= "{$status} *#{$o->code}*\n";
            if ($o->customer?->name) {
                $message .= "   👤 {$o->customer->name}\n";
            }
            $message .= "   📦 {$itemsCount} productos";
            if ($total > 0) {
                $message .= " · 💰 " . $this->formatPeso($total);
            }
            $palletsList = $o->pallets->pluck('code')->join(', ');
            if ($palletsList) {
                $message .= "\n   📦 Pallets: {$palletsList}";
            }
            $message .= "\n\n";
        }

        $message .= "_Usá /menu para ver el estado actual_";

        $this->reply($chatId, $message);
    }

    private function formatPeso(float $amount): string
    {
        return '$' . number_format($amount, 0, ',', '.');
    }
}
