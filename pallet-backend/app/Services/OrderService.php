<?php

namespace App\Services;

use App\Models\Order;

class OrderService
{
    /**
     * Verifica si un pedido puede ser finalizado.
     *
     * Reglas:
     *  - Debe tener al menos 1 ítem
     *  - Debe tener al menos 1 pallet asociado
     *  - Todos los ítems deben tener sus unidades distribuidas en bases de pallets
     *
     * @param  Order  $order  Debe tener cargadas las relaciones: items, pallets.bases.orderItems
     * @return array{can: bool, reason: string|null}
     */
    public static function canFinalize(Order $order): array
    {
        if ($order->items->isEmpty()) {
            return ['can' => false, 'reason' => 'El pedido no tiene productos'];
        }

        if ($order->pallets->isEmpty()) {
            return ['can' => false, 'reason' => 'El pedido debe tener al menos 1 pallet asociado para finalizar'];
        }

        // Sumar unidades asignadas por ítem a través de todas las bases de todos los pallets
        $assignedQtys = [];
        foreach ($order->pallets as $pallet) {
            foreach ($pallet->bases as $base) {
                foreach ($base->orderItems as $item) {
                    if ($item->order_id === $order->id) {
                        $assignedQtys[$item->id] = ($assignedQtys[$item->id] ?? 0) + $item->pivot->qty;
                    }
                }
            }
        }

        $allDistributed = $order->items->every(
            fn ($item) => ($assignedQtys[$item->id] ?? 0) >= $item->qty
        );

        if (! $allDistributed) {
            return ['can' => false, 'reason' => 'Todos los productos deben tener sus unidades distribuidas en bases de pallets'];
        }

        return ['can' => true, 'reason' => null];
    }

    /**
     * Enriquece un pedido con información de productos y calcula done_qty y locations.
     *
     * @param  Order  $order  Debe tener cargado: items.bases.pallet, items.bases.pivot
     * @return \Illuminate\Support\Collection
     */
    public static function enrichItemsWithLocations(Order $order): \Illuminate\Support\Collection
    {
        $eans = $order->items->pluck('ean')->filter()->unique()->values()->all();
        $productInfo = empty($eans) ? [] : \App\Models\Product::infoByEans($eans);

        return $order->items->map(function ($item) use ($productInfo) {
            $bases = $item->bases;

            $locations = $bases->map(fn($base) => [
                'pallet_id'   => $base->pallet->id,
                'pallet_code' => $base->pallet->code,
                'base_id'     => $base->id,
                'base_name'   => $base->name,
                'qty'         => $base->pivot->qty,
            ]);

            $calculatedDoneQty = $bases->sum(fn($base) => $base->pivot->qty ?? 0);

            if ($item->status === 'done' && $calculatedDoneQty === 0 && $bases->isEmpty()) {
                $calculatedDoneQty = $item->qty;
            }

            $prod = $productInfo[$item->ean] ?? null;

            return [
                'id'              => $item->id,
                'ean'             => $item->ean,
                'ean_last4'       => $item->ean_last4,
                'description'     => $item->description,
                'qty'             => $item->qty,
                'status'          => $item->status,
                'done_qty'        => $calculatedDoneQty,
                'locations'       => $locations,
                'image_url'       => $prod?->image_url ?? null,
                'units_per_bulto' => $prod?->units_per_bulto ?? null,
            ];
        });
    }

    /**
     * Determina si el pedido está listo para mostrar el mapa de highlights.
     * Requiere: todas las unidades distribuidas Y al menos 2 pallets distintos.
     */
    public static function isHighlightsReady(\Illuminate\Support\Collection $itemsWithLocations): bool
    {
        $allDistributed = $itemsWithLocations->every(fn ($i) => $i['done_qty'] >= $i['qty']);
        $distinctPalletIds = $itemsWithLocations
            ->flatMap(fn ($i) => collect($i['locations'])->pluck('pallet_id'))
            ->unique();

        return $allDistributed && $distinctPalletIds->count() >= 2;
    }

    /**
     * Enrich collection of items with product info (image_url, units_per_bulto).
     * Accepta cualquier colección de modelos que tengan propiedad 'ean'.
     */
    public static function enrichWithProductInfo($items): void
    {
        $eans = $items->pluck('ean')->filter()->unique()->values()->all();

        if (empty($eans)) {
            return;
        }

        $products = \App\Models\Product::infoByEans($eans);

        foreach ($items as $item) {
            $prod = $products[$item->ean] ?? null;
            $item->setAttribute('image_url', $prod?->image_url ?? null);
            $item->setAttribute('units_per_bulto', $prod?->units_per_bulto ?? null);
        }
    }
}
