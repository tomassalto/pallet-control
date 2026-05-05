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
}
