<?php

namespace App\Services;

use App\Helpers\ActivityLogger;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use Illuminate\Support\Facades\DB;

class OrderImportService
{
    /**
     * Reescribe los ítems del pedido con las filas parseadas del archivo.
     * Orden de locks D-020: order_items del pedido por id → pedido →
     * asignaciones (order_item_id, base_id). Si alguna fila baja la
     * cantidad por debajo de lo ya asignado a pallets, no escribe nada
     * y devuelve el conflicto.
     *
     * @param array<int, array{ean: string, ean_last4: string|null, description: string, qty: int, done_qty: int, status: string, price: float|null, desc_medio_pago: float|null, is_controlled: bool}> $parsed
     * @return array{count: int}|array{error: array{message: string, conflicts: array<int, array{ean: string, assigned: int, requested: int, reason: 'reduced'|'removed'}>}, status: int}
     */
    public function import(Order $order, array $parsed): array
    {
        return DB::transaction(function () use ($order, $parsed) {
            $items = OrderItem::where('order_id', $order->id)
                ->orderBy('id')
                ->lockForUpdate()
                ->get();

            $lockedOrder = Order::query()->lockForUpdate()->findOrFail($order->id);

            $assignedByItem = collect();
            $itemIds = $items->pluck('id');
            if ($itemIds->isNotEmpty()) {
                // PostgreSQL no admite FOR UPDATE con GROUP BY: se bloquean
                // las filas y la suma se calcula en PHP.
                $assignedByItem = DB::table('pallet_base_order_items')
                    ->whereIn('order_item_id', $itemIds)
                    ->orderBy('order_item_id')
                    ->orderBy('base_id')
                    ->lockForUpdate()
                    ->get(['order_item_id', 'qty'])
                    ->groupBy('order_item_id')
                    ->map(fn ($filas) => (int) $filas->sum('qty'));
            }

            // Lo asignado se compara por EAN contra la cantidad pedida nueva.
            $assignedByEan = $items->mapWithKeys(
                fn (OrderItem $item) => [$item->ean => (int) ($assignedByItem[$item->id] ?? 0)]
            );

            $importEans = array_column($parsed, 'ean');
            $importEanSet = array_fill_keys($importEans, true);

            $conflicts = [];
            foreach ($parsed as $row) {
                $assigned = $assignedByEan[$row['ean']] ?? 0;
                if ($assigned > $row['qty']) {
                    $conflicts[] = [
                        'ean' => $row['ean'],
                        'assigned' => $assigned,
                        'requested' => $row['qty'],
                        'reason' => 'reduced',
                    ];
                }
            }

            // Un ítem ausente del archivo se borraría junto con sus
            // asignaciones (cascade): si tiene qty asignada, es conflicto.
            foreach ($items as $item) {
                if (isset($importEanSet[$item->ean])) {
                    continue;
                }
                $assigned = (int) ($assignedByItem[$item->id] ?? 0);
                if ($assigned > 0) {
                    $conflicts[] = [
                        'ean' => $item->ean,
                        'assigned' => $assigned,
                        'requested' => 0,
                        'reason' => 'removed',
                    ];
                }
            }

            if ($conflicts !== []) {
                return [
                    'error' => [
                        'message' => 'La importación reduce cantidades por debajo de lo ya asignado a pallets.',
                        'conflicts' => $conflicts,
                    ],
                    'status' => 409,
                ];
            }

            // Borrar sólo los ítems bloqueados que quedaron fuera del
            // archivo: uno creado por otra request después del lock no se
            // toca.
            $toDelete = $items->reject(fn (OrderItem $i) => isset($importEanSet[$i->ean]));
            $removed = $toDelete->count();

            if ($removed > 0) {
                OrderItem::whereIn('id', $toDelete->pluck('id'))->delete();
            }

            foreach ($parsed as $row) {
                Product::updateOrCreate(
                    ['ean' => $row['ean']],
                    ['name' => $row['description'], 'ean_last4' => $row['ean_last4']]
                );

                OrderItem::updateOrCreate(
                    [
                        'order_id' => $lockedOrder->id,
                        'ean' => $row['ean'],
                    ],
                    [
                        'ean_last4' => $row['ean_last4'],
                        'description' => $row['description'],
                        'qty' => $row['qty'],
                        'done_qty' => $row['done_qty'],
                        'status' => $row['status'],
                        'price' => $row['price'],
                        'desc_medio_pago' => $row['desc_medio_pago'],
                        'is_controlled' => $row['is_controlled'],
                    ]
                );
            }

            ActivityLogger::log(
                action: 'order_imported',
                entityType: 'order',
                entityId: $lockedOrder->id,
                description: "Pedido '{$lockedOrder->code}' importado: " . count($parsed) . ' productos',
                newValues: ['count' => count($parsed), 'removed' => $removed],
                orderId: $lockedOrder->id,
            );

            return ['count' => count($parsed)];
        });
    }
}
