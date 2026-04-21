<?php

namespace App\Http\Controllers\Api;

use App\Helpers\ActivityLogger;
use App\Helpers\ImageConverter;
use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\OrderTicket;
use App\Models\OrderTicketPhoto;
use App\Models\Pallet;
use App\Models\PalletBasePhoto;
use App\Models\PalletPhoto;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class BotController extends Controller
{
    /**
     * POST /api/bot/upload
     * Header: X-Bot-Secret: <secret>
     * Body (multipart): type, pallet_code|order_code, base_name (opcional), note (opcional), photo
     */
    public function uploadPhoto(Request $request)
    {
        // Verificar clave secreta del bot
        $secret = config('services.whatsapp_bot.secret');
        if (empty($secret) || $request->header('X-Bot-Secret') !== $secret) {
            return response()->json(['error' => 'Unauthorized'], 401);
        }

        $data = $request->validate([
            'type'        => ['required', 'in:pallet,base,ticket'],
            'pallet_code' => ['nullable', 'string', 'max:255'],
            'base_name'   => ['nullable', 'string', 'max:255'],
            'order_code'  => ['nullable', 'string', 'max:255'],
            'note'        => ['nullable', 'string', 'max:1000'],
            'photo'       => ['required', 'file', 'image', 'max:20480'],
        ]);

        try {
            return match ($data['type']) {
                'pallet' => $this->uploadToPallet($data),
                'base'   => $this->uploadToBase($data),
                'ticket' => $this->uploadToTicket($data),
            };
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException) {
            return response()->json(['error' => 'No encontrado. Verificá el código.'], 404);
        } catch (\Throwable $e) {
            Log::error('[Bot] uploadPhoto error: ' . $e->getMessage());
            return response()->json(['error' => $e->getMessage()], 422);
        }
    }

    private function uploadToPallet(array $data)
    {
        $pallet = Pallet::where('code', $data['pallet_code'])->firstOrFail();

        $path = ImageConverter::convertToWebP(
            $data['photo'],
            "pallets/{$pallet->id}",
            85, 4000, 4000
        );

        $photo = PalletPhoto::create([
            'pallet_id'     => $pallet->id,
            'path'          => $path,
            'original_name' => 'whatsapp-' . now()->format('Ymd_His') . '.jpg',
            'note'          => $data['note'] ?? 'Subida vía WhatsApp',
        ]);

        ActivityLogger::log(
            'pallet_photo_uploaded',
            'pallet_photo',
            $photo->id,
            "Foto agregada al pallet '{$pallet->code}' vía WhatsApp",
            $pallet->id,
            null,
            ['photo_id' => $photo->id, 'via' => 'whatsapp']
        );

        return response()->json([
            'ok'  => true,
            'msg' => "✅ Foto guardada en pallet *{$pallet->code}*",
            'url' => '/storage/' . $path,
        ], 201);
    }

    private function uploadToBase(array $data)
    {
        $pallet = Pallet::where('code', $data['pallet_code'])->firstOrFail();

        $baseName = $data['base_name'] ?? null;
        $baseQuery = $pallet->bases();

        if ($baseName) {
            $base = $baseQuery->where('name', 'like', '%' . $baseName . '%')->firstOrFail();
        } else {
            // Si no se especifica nombre, usar la última base
            $base = $baseQuery->latest()->firstOrFail();
        }

        $path = ImageConverter::convertToWebP(
            $data['photo'],
            "pallets/{$pallet->id}/bases/{$base->id}",
            85, 4000, 4000
        );

        $photo = PalletBasePhoto::create([
            'base_id'       => $base->id,
            'path'          => $path,
            'original_name' => 'whatsapp-' . now()->format('Ymd_His') . '.jpg',
            'note'          => $data['note'] ?? 'Subida vía WhatsApp',
        ]);

        ActivityLogger::log(
            'base_photo_uploaded',
            'pallet_base_photo',
            $photo->id,
            "Foto agregada a base '" . ($base->name ?? 'Sin nombre') . "' del pallet '{$pallet->code}' vía WhatsApp",
            $pallet->id,
            null,
            ['photo_id' => $photo->id, 'via' => 'whatsapp']
        );

        return response()->json([
            'ok'  => true,
            'msg' => "✅ Foto guardada en base *" . ($base->name ?? 'Sin nombre') . "* del pallet *{$pallet->code}*",
            'url' => '/storage/' . $path,
        ], 201);
    }

    private function uploadToTicket(array $data)
    {
        $order = Order::where('code', $data['order_code'])->firstOrFail();

        $ticket = OrderTicket::create([
            'order_id' => $order->id,
            'code'     => null,
            'note'     => $data['note'] ?? 'Ticket vía WhatsApp',
        ]);

        $path = ImageConverter::convertToWebP(
            $data['photo'],
            "orders/{$order->id}/tickets/{$ticket->id}",
            85, 4000, 4000
        );

        $photo = OrderTicketPhoto::create([
            'ticket_id'     => $ticket->id,
            'path'          => $path,
            'original_name' => 'whatsapp-' . now()->format('Ymd_His') . '.jpg',
            'note'          => $data['note'] ?? null,
            'order_index'   => 0,
        ]);

        ActivityLogger::log(
            'order_ticket_photo_uploaded',
            'order_ticket_photo',
            $photo->id,
            "Foto agregada al pedido '{$order->code}' vía WhatsApp",
            null, null,
            ['ticket_id' => $ticket->id, 'photo_id' => $photo->id, 'via' => 'whatsapp'],
            $order->id
        );

        return response()->json([
            'ok'  => true,
            'msg' => "✅ Foto guardada en ticket del pedido *#{$order->code}*",
            'url' => '/storage/' . $path,
        ], 201);
    }
}
