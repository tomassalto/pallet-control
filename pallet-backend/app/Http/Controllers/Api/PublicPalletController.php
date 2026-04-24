<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Pallet;
use App\Models\Product;

class PublicPalletController extends Controller
{
    /**
     * Vista pública de un pallet — accesible sin auth, usada por el QR.
     * Devuelve el pallet con sus pedidos y los ítems con imagen del producto.
     */
    public function show(string $code)
    {
        $pallet = Pallet::with([
            'orders.items',
        ])
        ->where('code', $code)
        ->first();

        if (!$pallet) {
            return response()->json(['message' => 'Pallet no encontrado'], 404);
        }

        // Pre-cargar imágenes de productos por EAN
        $eans = $pallet->orders
            ->flatMap(fn($o) => $o->items->pluck('ean'))
            ->unique()
            ->values()
            ->toArray();

        $images = Product::whereIn('ean', $eans)
            ->whereNotNull('image_url')
            ->pluck('image_url', 'ean');

        // Armar respuesta limpia
        $orders = $pallet->orders->map(function ($order) use ($images) {
            $items = $order->items
                ->where('done_qty', '>', 0)   // solo ítems entregados
                ->sortBy('description')
                ->map(fn($item) => [
                    'ean'         => $item->ean,
                    'description' => $item->description,
                    'qty'         => $item->done_qty,
                    'image_url'   => $images[$item->ean] ?? null,
                ])
                ->values();

            return [
                'id'     => $order->id,
                'code'   => $order->code,
                'items'  => $items,
            ];
        })->values();

        return response()->json([
            'code'   => $pallet->code,
            'status' => $pallet->status,
            'orders' => $orders,
        ]);
    }
}
