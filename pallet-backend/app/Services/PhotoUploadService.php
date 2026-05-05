<?php

namespace App\Services;

use App\Helpers\ActivityLogger;
use App\Helpers\ImageConverter;
use App\Models\Order;
use App\Models\OrderTicket;
use App\Models\OrderTicketPhoto;
use App\Models\Pallet;
use App\Models\PalletBasePhoto;
use App\Models\PalletPhoto;
use Illuminate\Http\UploadedFile;

class PhotoUploadService
{
    /**
     * Sube una foto al destino indicado en $params['type'].
     * Devuelve ['ok' => true, 'msg' => '...', 'url' => '...']
     */
    public function upload(UploadedFile $photo, array $params): array
    {
        $params['photo'] = $photo;

        return match ($params['type']) {
            'pallet' => $this->toPallet($params),
            'base'   => $this->toBase($params),
            'ticket' => $this->toTicket($params),
            default  => throw new \InvalidArgumentException("Tipo inválido: {$params['type']}"),
        };
    }

    // ─────────────────────────────────────────────────────
    // Resolvers
    // ─────────────────────────────────────────────────────

    public function resolvePallet(array $data): Pallet
    {
        if (!empty($data['pallet_index'])) {
            return Pallet::where('status', 'open')
                ->orderByDesc('id')
                ->skip($data['pallet_index'] - 1)
                ->firstOrFail();
        }
        return Pallet::where('code', $data['pallet_code'])->firstOrFail();
    }

    public function resolveOrder(array $data): Order
    {
        if (!empty($data['order_index'])) {
            return Order::where('status', 'open')
                ->orderByDesc('id')
                ->skip($data['order_index'] - 1)
                ->firstOrFail();
        }
        return Order::where('code', $data['order_code'])->firstOrFail();
    }

    // ─────────────────────────────────────────────────────
    // Uploads
    // ─────────────────────────────────────────────────────

    private function toPallet(array $data): array
    {
        $pallet = $this->resolvePallet($data);

        $path = ImageConverter::convertToWebP(
            $data['photo'], "pallets/{$pallet->id}", 85, 4000, 4000
        );

        $photo = PalletPhoto::create([
            'pallet_id'     => $pallet->id,
            'path'          => $path,
            'original_name' => 'bot-' . now()->format('Ymd_His') . '.jpg',
            'note'          => $data['note'] ?? 'Subida vía bot',
        ]);

        ActivityLogger::log(
            action: 'pallet_photo_uploaded',
            entityType: 'pallet_photo',
            entityId: $photo->id,
            description: "Foto agregada al pallet '{$pallet->code}' vía bot",
            palletId: $pallet->id,
            newValues: ['photo_id' => $photo->id, 'via' => 'bot'],
        );

        return [
            'ok'  => true,
            'msg' => "✅ Foto guardada en pallet *{$pallet->code}*",
            'url' => \Illuminate\Support\Facades\Storage::disk(config('filesystems.default', 'public'))->url($path),
        ];
    }

    private function toBase(array $data): array
    {
        $pallet = $this->resolvePallet($data);

        $baseQuery = $pallet->bases();

        if (!empty($data['base_name'])) {
            $base = $baseQuery->where('name', 'like', '%' . $data['base_name'] . '%')->firstOrFail();
        } else {
            $base = $baseQuery->latest()->firstOrFail();
        }

        $path = ImageConverter::convertToWebP(
            $data['photo'], "pallets/{$pallet->id}/bases/{$base->id}", 85, 4000, 4000
        );

        $photo = PalletBasePhoto::create([
            'base_id'       => $base->id,
            'path'          => $path,
            'original_name' => 'bot-' . now()->format('Ymd_His') . '.jpg',
            'note'          => $data['note'] ?? 'Subida vía bot',
        ]);

        $baseName = $base->name ?? 'Sin nombre';

        ActivityLogger::log(
            action: 'base_photo_uploaded',
            entityType: 'pallet_base_photo',
            entityId: $photo->id,
            description: "Foto agregada a base '{$baseName}' del pallet '{$pallet->code}' vía bot",
            palletId: $pallet->id,
            newValues: ['photo_id' => $photo->id, 'via' => 'bot'],
        );

        return [
            'ok'  => true,
            'msg' => "✅ Foto guardada en base *{$baseName}* del pallet *{$pallet->code}*",
            'url' => \Illuminate\Support\Facades\Storage::disk(config('filesystems.default', 'public'))->url($path),
        ];
    }

    private function toTicket(array $data): array
    {
        $order = $this->resolveOrder($data);

        $ticket = OrderTicket::create([
            'order_id' => $order->id,
            'code'     => null,
            'note'     => $data['note'] ?? 'Ticket vía bot',
        ]);

        $path = ImageConverter::convertToWebP(
            $data['photo'], "orders/{$order->id}/tickets/{$ticket->id}", 95, 6000, 6000
        );

        $photo = OrderTicketPhoto::create([
            'ticket_id'     => $ticket->id,
            'path'          => $path,
            'original_name' => 'bot-' . now()->format('Ymd_His') . '.jpg',
            'note'          => $data['note'] ?? null,
            'order_index'   => 0,
        ]);

        ActivityLogger::log(
            action: 'order_ticket_photo_uploaded',
            entityType: 'order_ticket_photo',
            entityId: $photo->id,
            description: "Foto agregada al pedido '{$order->code}' vía bot",
            newValues: ['ticket_id' => $ticket->id, 'photo_id' => $photo->id, 'via' => 'bot'],
            orderId: $order->id,
        );

        return [
            'ok'  => true,
            'msg' => "✅ Foto guardada en ticket del pedido *#{$order->code}*",
            'url' => \Illuminate\Support\Facades\Storage::disk(config('filesystems.default', 'public'))->url($path),
        ];
    }
}
