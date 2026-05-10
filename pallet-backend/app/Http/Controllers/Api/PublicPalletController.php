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

        // ── Pre-cargar info de productos (1 sola query) ───────────────────
        $eans = collect();
        foreach ($pallet->bases as $b) {
            $eans = $eans->merge($b->orderItems->pluck('ean'));
        }
        $products = Product::infoByEans($eans->unique()->values()->toArray());

        // ── Fotos del pallet ──────────────────────────────────────────────
        $photos = $pallet->photos->map(fn ($p) => [
            'id'  => $p->id,
            'url' => $p->url,
        ])->values();

        // ── Mapa EAN → bases (para mostrar desglose en highlights) ────────
        // Construido en un único recorrido sobre las bases del pallet.
        $eanBasesMap      = []; // [ean]           => [{base_id, name, qty}, …]
        $orderEanBasesMap = []; // [order_id][ean] => [{base_id, name, qty}, …]

        $mergeBase = function (array &$list, int $baseId, string $baseName, int $qty): void {
            foreach ($list as &$entry) {
                if ($entry['base_id'] === $baseId) {
                    $entry['qty'] += $qty;
                    return;
                }
            }
            unset($entry);
            $list[] = ['base_id' => $baseId, 'name' => $baseName, 'qty' => $qty];
        };

        foreach ($pallet->bases as $base) {
            $baseName = $base->name ?: "Base #{$base->id}";
            foreach ($base->orderItems as $item) {
                $ean     = $item->ean;
                $orderId = $item->order_id;
                $qty     = (int) ($item->pivot->qty ?? 0);

                $eanBasesMap[$ean] ??= [];
                $mergeBase($eanBasesMap[$ean], $base->id, $baseName, $qty);

                $orderEanBasesMap[$orderId]         ??= [];
                $orderEanBasesMap[$orderId][$ean]   ??= [];
                $mergeBase($orderEanBasesMap[$orderId][$ean], $base->id, $baseName, $qty);
            }
        }

        // ── Resumen de pedidos: SOLO los ítems asignados a bases ──────────
        $palletItemsMap = [];
        foreach ($pallet->bases as $base) {
            foreach ($base->orderItems as $item) {
                if (isset($palletItemsMap[$item->id])) {
                    $palletItemsMap[$item->id]['qty'] += $item->pivot->qty;
                } else {
                    $palletItemsMap[$item->id] = [
                        'item'      => $item,
                        'qty'       => $item->pivot->qty,
                        'qty_order' => $item->qty, // total pedido (no solo en pallet)
                    ];
                }
            }
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

            $prod = $products[$orderItem->ean] ?? null;
            $orderMap[$orderId]['items'][] = [
                'ean'             => $orderItem->ean,
                'description'     => $orderItem->description,
                'qty'             => $data['qty'],
                'qty_order'       => $data['qty_order'],
                'image_url'       => $prod?->image_url ?? null,
                'units_per_bulto' => $prod?->units_per_bulto ?? null,
            ];
        }

        foreach ($orderMap as &$o) {
            usort($o['items'], fn ($a, $b) => strcmp($a['description'], $b['description']));
        }
        $orders = array_values($orderMap);

        // ── Mapa EAN → info del pallet (para OCR matching) ───────────────
        // Se construye desde los mismos productos renderizados en Pallet View.
        $palletEanMap = [];
        $orderScopedEanMaps = [];
        foreach ($orders as $orderData) {
            foreach ($orderData['items'] as $item) {
                $ean = $item['ean'] ?? null;
                if (! $ean) {
                    continue;
                }

                if (! isset($palletEanMap[$ean])) {
                    $palletEanMap[$ean] = [
                        'description'     => $item['description'],
                        'total_qty'       => 0,
                        'qty_order_total' => 0, // suma de qty_order de todos los pedidos
                        'units_per_bulto' => $item['units_per_bulto'] ?? null,
                        'orders'          => [],
                        'bases'           => $eanBasesMap[$ean] ?? [],
                    ];
                }

                $palletEanMap[$ean]['total_qty']       += (int) ($item['qty'] ?? 0);
                $palletEanMap[$ean]['qty_order_total'] += (int) ($item['qty_order'] ?? $item['qty']);
                $palletEanMap[$ean]['orders'][] = [
                    'code' => $orderData['code'],
                    'qty'  => (int) ($item['qty'] ?? 0),
                ];

                $orderId = (int) $orderData['id'];
                if (! isset($orderScopedEanMaps[$orderId])) {
                    $orderScopedEanMaps[$orderId] = [];
                }
                if (! isset($orderScopedEanMaps[$orderId][$ean])) {
                    $orderScopedEanMaps[$orderId][$ean] = [
                        'description'     => $item['description'],
                        'total_qty'       => 0,
                        'qty_order_total' => 0,
                        'units_per_bulto' => $item['units_per_bulto'] ?? null,
                        'orders'          => [],
                        'bases'           => $orderEanBasesMap[$orderId][$ean] ?? [],
                    ];
                }
                $orderScopedEanMaps[$orderId][$ean]['total_qty']       += (int) ($item['qty'] ?? 0);
                $orderScopedEanMaps[$orderId][$ean]['qty_order_total'] += (int) ($item['qty_order'] ?? $item['qty']);
                $orderScopedEanMaps[$orderId][$ean]['orders'][] = [
                    'code' => $orderData['code'],
                    'qty'  => (int) ($item['qty'] ?? 0),
                ];
            }
        }

        // ── Bases: fotos + ítems agrupados por pedido ─────────────────────
        $bases = $pallet->bases->map(function ($base) use ($products) {
            $basePhotos = $base->photos->map(fn ($p) => [
                'id'  => $p->id,
                'url' => $p->url,
            ])->values();

            $orderGroups = $base->orderItems
                ->groupBy('order_id')
                ->map(function ($items, $orderId) use ($products) {
                    $first = $items->first();
                    return [
                        'order_id'   => $orderId,
                        'order_code' => $first->order?->code ?? "#{$orderId}",
                        'customer'   => $first->order?->customer?->name,
                        'items'      => $items->sortBy('description')->map(function ($item) use ($products) {
                            $prod = $products[$item->ean] ?? null;
                            return [
                                'ean'             => $item->ean,
                                'description'     => $item->description,
                                'qty'             => $item->pivot->qty,
                                'image_url'       => $prod?->image_url ?? null,
                                'units_per_bulto' => $prod?->units_per_bulto ?? null,
                            ];
                        })->values(),
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

            $orderScopedMap = $orderScopedEanMaps[$order->id] ?? $palletEanMap;

            $ticketList = $order->tickets->map(function ($ticket) use ($orderScopedMap) {
                // Solo incluir fotos que ya fueron escaneadas con OCR.
                // Fotos sin escanear no se muestran en la vista pública.
                $photos = $ticket->photos
                    ->filter(fn ($photo) => $photo->ocr_processed_at !== null)
                    ->map(function ($photo) use ($orderScopedMap) {
                        $highlights = [];
                        if ($photo->ocr_data) {
                            $highlights = TicketOcrService::buildHighlights(
                                $photo->ocr_data,
                                $orderScopedMap
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

                // No incluir tickets sin fotos escaneadas
                if ($photos->isEmpty()) {
                    return null;
                }

                return [
                    'id'     => $ticket->id,
                    'code'   => $ticket->code,
                    'note'   => $ticket->note,
                    'photos' => $photos,
                ];
            })->filter()->values(); // filter() elimina los null

            // No incluir secciones de pedidos sin tickets con fotos escaneadas
            if ($ticketList->isEmpty()) {
                continue;
            }

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
