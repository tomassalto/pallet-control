<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\Product;
use App\Services\TicketOcrService;

class PublicOrderController extends Controller
{
    /**
     * Vista pública de un pedido — accesible sin auth.
     *
     * Muestra todos los pallets del pedido con sus bases y productos,
     * más los tickets con highlights OCR y el resumen de ítems.
     */
    public function show(string $code)
    {
        $order = Order::with([
            'customer',
            'items'                    => fn ($q) => $q->orderBy('description'),
            'tickets.photos',
            'pallets'                  => fn ($q) => $q->orderBy('created_at'),
            'pallets.photos'           => fn ($q) => $q->orderBy('created_at'),
            'pallets.bases'            => fn ($q) => $q->orderBy('created_at'),
            'pallets.bases.photos'     => fn ($q) => $q->orderBy('created_at'),
            'pallets.bases.orderItems',
        ])
        ->where('code', $code)
        ->first();

        if (! $order) {
            return response()->json(['message' => 'Pedido no encontrado'], 404);
        }

        // ── Pre-cargar info de productos (1 sola query) ───────────────────
        $allEans = $order->items->pluck('ean')->unique()->values()->toArray();
        $products = Product::infoByEans($allEans);

        // ── Total del pedido ──────────────────────────────────────────────
        $totalPrice = $order->items->reduce(function ($carry, $item) {
            if ($item->price !== null) {
                $carry += $item->price * $item->qty;
            }
            return $carry;
        }, 0.0);

        // ── Items del pedido ──────────────────────────────────────────────
        $items = $order->items->map(function ($item) use ($products) {
            $prod = $products[$item->ean] ?? null;
            return [
                'id'              => $item->id,
                'ean'             => $item->ean,
                'description'     => $item->description,
                'qty'             => $item->qty,
                'price'           => $item->price,
                'image_url'       => $prod?->image_url ?? null,
                'units_per_bulto' => $prod?->units_per_bulto ?? null,
            ];
        })->values();

        // ── Mapa EAN para OCR highlights ──────────────────────────────────
        // Construido desde todos los ítems del pedido (no solo los que están en bases).
        $orderEanMap = [];
        foreach ($order->items as $item) {
            $prod = $products[$item->ean] ?? null;
            $orderEanMap[$item->ean] = [
                'description'     => $item->description,
                'total_qty'       => $item->qty,
                'qty_order_total' => $item->qty,
                'units_per_bulto' => $prod?->units_per_bulto ?? null,
                'orders'          => [['code' => $order->code, 'qty' => $item->qty]],
                'bases'           => [],
            ];
        }

        // ── Pallets con bases ─────────────────────────────────────────────
        $pallets = $order->pallets->map(function ($pallet) use ($products, $order) {
            $bases = $pallet->bases->map(function ($base) use ($products, $order) {
                $basePhotos = $base->photos->map(fn ($p) => [
                    'id'  => $p->id,
                    'url' => $p->url,
                ])->values();

                // Solo mostrar ítems de ESTE pedido en cada base
                $baseItems = $base->orderItems
                    ->filter(fn ($item) => $item->order_id === $order->id)
                    ->sortBy('description')
                    ->map(function ($item) use ($products) {
                        $prod = $products[$item->ean] ?? null;
                        return [
                            'ean'             => $item->ean,
                            'description'     => $item->description,
                            'qty'             => $item->pivot->qty,
                            'image_url'       => $prod?->image_url ?? null,
                            'units_per_bulto' => $prod?->units_per_bulto ?? null,
                        ];
                    })->values();

                return [
                    'id'     => $base->id,
                    'name'   => $base->name,
                    'photos' => $basePhotos,
                    'items'  => $baseItems,
                ];
            })->values();

            $palletPhotos = $pallet->photos->map(fn ($p) => [
                'id'  => $p->id,
                'url' => $p->url,
            ])->values();

            return [
                'id'     => $pallet->id,
                'code'   => $pallet->code,
                'status' => $pallet->status,
                'photos' => $palletPhotos,
                'bases'  => $bases,
            ];
        })->values();

        // ── Tickets con OCR highlights ─────────────────────────────────────
        $ticketSections = $order->tickets->map(function ($ticket) use ($orderEanMap) {
            $photos = $ticket->photos
                ->filter(fn ($photo) => $photo->ocr_processed_at !== null)
                ->map(function ($photo) use ($orderEanMap) {
                    $highlights = [];
                    if ($photo->ocr_data) {
                        $highlights = TicketOcrService::buildHighlights(
                            $photo->ocr_data,
                            $orderEanMap
                        );
                    }
                    return [
                        'id'              => $photo->id,
                        'url'             => $photo->url,
                        'ocr_processed'   => true,
                        'highlight_count' => count($highlights),
                        'highlights'      => $highlights,
                    ];
                })->values();

            if ($photos->isEmpty()) {
                return null;
            }

            return [
                'ticket_id' => $ticket->id,
                'code'      => $ticket->code,
                'note'      => $ticket->note,
                'photos'    => $photos,
            ];
        })->filter()->values();

        return response()->json([
            'code'            => $order->code,
            'status'          => $order->status,
            'customer'        => $order->customer?->name,
            'total_price'     => $totalPrice ?: null,
            'items'           => $items,
            'pallets'         => $pallets,
            'ticket_sections' => $ticketSections,
        ]);
    }
}
