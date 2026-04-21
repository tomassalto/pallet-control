<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\PhotoUploadService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class BotController extends Controller
{
    /**
     * POST /api/v1/bot/upload
     * Header: X-Bot-Secret: <secret>
     * Body (multipart): type, pallet_code|pallet_index|order_code|order_index, base_name, note, photo
     *
     * Mantenido para compatibilidad con el bot de WhatsApp (si se sigue usando).
     */
    public function uploadPhoto(Request $request)
    {
        $secret = config('services.whatsapp_bot.secret');
        if (empty($secret) || $request->header('X-Bot-Secret') !== $secret) {
            return response()->json(['error' => 'Unauthorized'], 401);
        }

        $data = $request->validate([
            'type'         => ['required', 'in:pallet,base,ticket'],
            'pallet_code'  => ['nullable', 'string', 'max:255'],
            'pallet_index' => ['nullable', 'integer', 'min:1', 'max:99'],
            'base_name'    => ['nullable', 'string', 'max:255'],
            'order_code'   => ['nullable', 'string', 'max:255'],
            'order_index'  => ['nullable', 'integer', 'min:1', 'max:99'],
            'note'         => ['nullable', 'string', 'max:1000'],
            'photo'        => ['required', 'file', 'image', 'max:20480'],
        ]);

        try {
            $result = app(PhotoUploadService::class)->upload($data['photo'], $data);
            return response()->json($result, 201);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException) {
            return response()->json(['error' => 'No encontrado. Verificá el código.'], 404);
        } catch (\Throwable $e) {
            Log::error('[Bot] uploadPhoto error: ' . $e->getMessage());
            return response()->json(['error' => $e->getMessage()], 422);
        }
    }
}
