<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Pallet;
use App\Models\Product;

class PublicPalletController extends Controller
{
    /**
     * Vista pública de un pallet — accesible sin auth, usada por el QR.
     * Devuelve el pallet completo: pedidos, fotos del pallet y bases con sus
     * fotos y productos agrupados por pedido.
     */
    public function show(string $code)
    {
        $pallet = Pallet::with([
            'orders.items',
            'orders.customer',
            'photos',
            'bases'                 => fn ($q) => $q->orderBy('created_at'),
            'bases.photos'          => fn ($q) => $q->orderBy('created_at'),
            'bases.orderItems.order.customer',
        ])
        ->where('code', $code)
        ->first();

        if (!$pallet) {
            return response()->json(['message' => 'Pallet no encontrado'], 404);
        }

        // ── Recolectar todos los EANs (pedidos + bases) para 1 sola query de imágenes
        $eans = collect();
        foreach ($pallet->orders as $o) {
            $eans = $eans->merge($o->items->pluck('ean'));
        }
        foreach ($pallet->bases as $b) {
            $eans = $eans->merge($b->orderItems->pluck('ean'));
        }
        $images = Product::whereIn('ean', $eans->unique()->values()->toArray())
            ->whereNotNull('image_url')
            ->pluck('image_url', 'ean');

        // ── Fotos del pallet
        $photos = $pallet->photos->map(fn ($p) => [
            'id'  => $p->id,
            'url' => $p->url,
        ])->values();

        // ── Resumen de pedidos
        $orders = $pallet->orders->map(function ($order) use ($images) {
            $items = $order->items
                ->where('done_qty', '>', 0)
                ->sortBy('description')
                ->map(fn ($item) => [
                    'ean'         => $item->ean,
                    'description' => $item->description,
                    'qty'         => $item->done_qty,
                    'image_url'   => $images[$item->ean] ?? null,
                ])
                ->values();

            return [
                'id'       => $order->id,
                'code'     => $order->code,
                'customer' => $order->customer?->name,
                'items'    => $items,
            ];
        })->values();

        // ── Bases: fotos + productos agrupados por pedido
        $bases = $pallet->bases->map(function ($base) use ($images) {
            $basePhotos = $base->photos->map(fn ($p) => [
                'id'  => $p->id,
                'url' => $p->url,
            ])->values();

            // Agrupar orderItems por pedido
            $orderGroups = $base->orderItems
                ->groupBy('order_id')
                ->map(function ($items, $orderId) use ($images) {
                    $first = $items->first();
                    return [
                        'order_id'   => $orderId,
                        'order_code' => $first->order?->code ?? "#{$orderId}",
                        'customer'   => $first->order?->customer?->name,
                        'items'      => $items->sortBy('description')->map(fn ($item) => [
                            'ean'         => $item->ean,
                            'description' => $item->description,
                            'qty'         => $item->pivot->qty,
                            'image_url'   => $images[$item->ean] ?? null,
                        ])->values(),
                    ];
                })
                ->values();

            return [
                'id'     => $base->id,
                'name'   => $base->name,
                'photos' => $basePhotos,
                'orders' => $orderGroups,
            ];
        })->values();

        return response()->json([
            'code'   => $pallet->code,
            'status' => $pallet->status,
            'photos' => $photos,
            'orders' => $orders,
            'bases'  => $bases,
        ]);
    }
}
