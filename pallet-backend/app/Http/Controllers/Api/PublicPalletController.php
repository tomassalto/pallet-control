<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Pallet;
use App\Models\Product;
use App\Services\TicketOcrService;

class PublicPalletController extends Controller
{
    /**
     * Vista pública de un pallet — accesible sin auth, usada por el QR.
     *
     * La sección "orders" muestra únicamente los productos que están
     * asignados a alguna base de este pallet (no todos los ítems del pedido).
     * Las cantidades son la suma de todas las bases donde aparece ese ítem.
     *
     * La sección "ticket_highlights" incluye los tickets del pedido con
     * sus fotos y los overlays de EAN detectados por OCR.
     */
    public function show(string $code)
    {
        $pallet = Pallet::with([
            'orders.customer',
            'orders.tickets.photos',      // ← tickets para la vista pública
            'photos',
            'bases'                => fn ($q) => $q->orderBy('created_at'),
            'bases.photos'         => fn ($q) => $q->orderBy('created_at'),
            'bases.orderItems.order.customer',
        ])
        ->where('code', $code)
        ->first();

        if (!$pallet) {
            return response()->json(['message' => 'Pallet no encontrado'], 404);
        }

        // ── Pre-cargar imágenes (1 sola query) ────────────────────────────
        $eans = collect();
        foreach ($pallet->bases as $b) {
            $eans = $eans->merge($b->orderItems->pluck('ean'));
        }
        $images = Product::whereIn('ean', $eans->unique()->values()->toArray())
            ->whereNotNull('image_url')
            ->pluck('image_url', 'ean');

        // ── Fotos del pallet ──────────────────────────────────────────────
        $photos = $pallet->photos->map(fn ($p) => [
            'id'  => $p->id,
            'url' => $p->url,
        ])->values();

        // ── Resumen de pedidos: SOLO los ítems asignados a bases ──────────
        $palletItemsMap = [];
        foreach ($pallet->bases as $base) {
            foreach ($base->orderItems as $item) {
                if (isset($palletItemsMap[$item->id])) {
                    $palletItemsMap[$item->id]['qty'] += $item->pivot->qty;
                } else {
                    $palletItemsMap[$item->id] = [
                        'item' => $item,
                        'qty'  => $item->pivot->qty,
                    ];
                }
            }
        }

        // ── Mapa EAN → info del pallet (para OCR matching) ───────────────
        // Agrupa por EAN acumulando cantidades de todos los pedidos/bases.
        $palletEanMap = [];
        foreach ($palletItemsMap as $data) {
            $item    = $data['item'];
            $ean     = $item->ean;
            $orderId = $item->order_id;
            $order   = $pallet->orders->firstWhere('id', $orderId);

            if (! $ean) continue;

            if (! isset($palletEanMap[$ean])) {
                $palletEanMap[$ean] = [
                    'description' => $item->description,
                    'total_qty'   => 0,
                    'orders'      => [],
                ];
            }

            $palletEanMap[$ean]['total_qty'] += $data['qty'];
            $palletEanMap[$ean]['orders'][]   = [
                'code' => $order?->code ?? "#{$orderId}",
                'qty'  => $data['qty'],
            ];
        }

        // Agrupar por pedido para la sección de orders
        $orderMap = [];
        foreach ($palletItemsMap as $data) {
            $orderItem = $data['item'];
            $orderId   = $orderItem->order_id;

            if (!isset($orderMap[$orderId])) {
                $order = $pallet->orders->firstWhere('id', $orderId);
                $orderMap[$orderId] = [
                    'id'       => $orderId,
                    'code'     => $order?->code ?? "#{$orderId}",
                    'customer' => $order?->customer?->name,
                    'items'    => [],
                ];
            }

            $orderMap[$orderId]['items'][] = [
                'ean'         => $orderItem->ean,
                'description' => $orderItem->description,
                'qty'         => $data['qty'],
                'image_url'   => $images[$orderItem->ean] ?? null,
            ];
        }

        foreach ($orderMap as &$o) {
            usort($o['items'], fn ($a, $b) => strcmp($a['description'], $b['description']));
        }
        $orders = array_values($orderMap);

        // ── Bases: fotos + ítems agrupados por pedido ─────────────────────
        $bases = $pallet->bases->map(function ($base) use ($images) {
            $basePhotos = $base->photos->map(fn ($p) => [
                'id'  => $p->id,
                'url' => $p->url,
            ])->values();

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

        // ── Tickets con OCR highlights ─────────────────────────────────────
        // Para cada pedido del pallet, incluimos sus tickets con sus fotos.
        // Si la foto tiene ocr_data procesado, calculamos los highlights.
        $ticketsByOrder = [];
        foreach ($pallet->orders as $order) {
            if ($order->tickets->isEmpty()) continue;

            $ticketList = $order->tickets->map(function ($ticket) use ($palletEanMap) {
                $photos = $ticket->photos->map(function ($photo) use ($palletEanMap) {
                    $highlights = [];

                    if ($photo->ocr_processed_at && $photo->ocr_data) {
                        $highlights = TicketOcrService::buildHighlights(
                            $photo->ocr_data,
                            $palletEanMap
                        );
                    }

                    return [
                        'id'                => $photo->id,
                        'url'               => $photo->url,
                        'ocr_processed'     => $photo->ocr_processed_at !== null,
                        'highlight_count'   => count($highlights),
                        'highlights'        => $highlights,
                    ];
                })->values();

                return [
                    'id'     => $ticket->id,
                    'code'   => $ticket->code,
                    'note'   => $ticket->note,
                    'photos' => $photos,
                ];
            })->values();

            $ticketsByOrder[] = [
                'order_id'   => $order->id,
                'order_code' => $order->code,
                'customer'   => $order->customer?->name,
                'tickets'    => $ticketList,
            ];
        }

        return response()->json([
            'code'            => $pallet->code,
            'status'          => $pallet->status,
            'photos'          => $photos,
            'orders'          => $orders,
            'bases'           => $bases,
            'ticket_sections' => $ticketsByOrder,
        ]);
    }
}
