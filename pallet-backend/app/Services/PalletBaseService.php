<?php

namespace App\Services;

use App\Helpers\ActivityLogger;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Pallet;
use App\Models\PalletBase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class PalletBaseService
{
    public function createBase(Pallet $pallet, array $data): array
    {
        return DB::transaction(function () use ($pallet, $data) {
            $itemsToSync = [];
            $orderItemsMap = collect();

            if (! empty($data['items'])) {
                $requestedItemIds = collect($data['items'])
                    ->pluck('order_item_id')
                    ->unique()
                    ->sort()
                    ->values();
                $palletOrderIds = $pallet->orders()->pluck('orders.id');

                [$orderItemsMap, $ordersMap] = $this->fetchOrderData($requestedItemIds, lock: true);
                $allocations = $this->fetchAllocations(null, $requestedItemIds, lock: true);

                foreach ($data['items'] as $item) {
                    $orderItem = $orderItemsMap->get($item['order_item_id']);
                    if (! $orderItem || ! $palletOrderIds->contains($orderItem->order_id)) {
                        return [
                            'error' => ['message' => 'El producto no pertenece a un pedido asociado a este pallet.'],
                            'status' => 409,
                        ];
                    }

                    $order = $ordersMap->get($orderItem->order_id);
                    if ($order && $order->status === 'done') {
                        return [
                            'error' => [
                                'message' => "No se puede asignar '{$orderItem->description}' porque el pedido '{$order->code}' está finalizado.",
                                'order_item_id' => $item['order_item_id'],
                                'order_code' => $order->code,
                            ],
                            'status' => 409,
                        ];
                    }

                    $totalAssigned = $allocations->get($item['order_item_id'], 0);
                    $available = $orderItem->qty - $totalAssigned;

                    if ($item['qty'] > $available) {
                        return [
                            'error' => [
                                'message' => "No se puede asignar {$item['qty']} unidades. Solo quedan {$available} disponibles de '{$orderItem->description}'.",
                                'order_item_id' => $item['order_item_id'],
                                'available' => $available,
                                'requested' => $item['qty'],
                            ],
                            'status' => 409,
                        ];
                    }

                    $itemsToSync[$item['order_item_id']] = ['qty' => $item['qty']];
                }
            }

            $base = PalletBase::create([
                'pallet_id' => $pallet->id,
                'name' => $data['name'] ?? null,
                'note' => $data['note'] ?? null,
            ]);

            if ($itemsToSync !== []) {
                $base->orderItems()->sync($itemsToSync);

                foreach ($itemsToSync as $orderItemId => $pivotData) {
                    $orderItem = $orderItemsMap->get($orderItemId);
                    if ($orderItem) {
                        ActivityLogger::log(
                            action: 'item_assigned_to_base',
                            entityType: 'order_item',
                            entityId: $orderItemId,
                            description: "Producto '{$orderItem->description}' asignado a base '" . ($base->name ?? 'Sin nombre') . "' - Cantidad: {$pivotData['qty']}",
                            palletId: $pallet->id,
                            newValues: ['base_id' => $base->id, 'base_name' => $base->name, 'qty' => $pivotData['qty']],
                        );
                    }
                }
            }

            ActivityLogger::log(
                action: 'base_created',
                entityType: 'pallet_base',
                entityId: $base->id,
                description: "Base creada: '" . ($base->name ?? 'Sin nombre') . "'",
                palletId: $pallet->id,
                newValues: ['name' => $base->name, 'note' => $base->note],
            );

            return ['base' => $base];
        });
    }

    public function updateBase(Pallet $pallet, PalletBase $base, array $data): array
    {
        if ($base->pallet_id !== $pallet->id) {
            return ['error' => ['message' => 'Base no encontrada'], 'status' => 404];
        }

        return DB::transaction(function () use ($pallet, $base, $data) {
            $base = PalletBase::query()->lockForUpdate()->findOrFail($base->id);
            $changeLog = $this->buildBaseChangeLog($base, $data);
            $syncResult = null;
            $oldItems = [];
            $orderItemsMap = collect();

            if (isset($data['items'])) {
                $palletOrderIds = $pallet->orders()->pluck('orders.id');
                $requestedItemIds = collect($data['items'])->pluck('order_item_id');
                $oldItemIds = DB::table('pallet_base_order_items')
                    ->where('base_id', $base->id)
                    ->pluck('order_item_id');
                $allItemIds = $requestedItemIds
                    ->merge($oldItemIds)
                    ->unique()
                    ->sort()
                    ->values();

                [$orderItemsMap, $ordersMap] = $this->fetchOrderData($allItemIds, lock: true);
                $oldItems = $base->orderItems()
                    ->orderBy('order_items.id')
                    ->lockForUpdate()
                    ->get()
                    ->mapWithKeys(fn ($item) => [$item->id => ['qty' => $item->pivot->qty]])
                    ->toArray();
                $allocations = $this->fetchAllocations($base, $requestedItemIds, lock: true);

                $syncResult = $this->syncBaseItems($palletOrderIds, $base, $data['items'], $oldItems, $orderItemsMap, $ordersMap, $allocations);

                if (isset($syncResult['error'])) {
                    return ['error' => $syncResult['error'], 'status' => 409];
                }

            }

            $base->update([
                'name' => $data['name'] ?? $base->name,
                'note' => $data['note'] ?? $base->note,
            ]);

            if ($syncResult !== null) {
                $this->logItemChanges($pallet, $base, $syncResult['items'], $oldItems, $syncResult['removed'], $orderItemsMap);
            }

            if (! empty($changeLog['descriptions'])) {
                ActivityLogger::log(
                    action: 'base_updated',
                    entityType: 'pallet_base',
                    entityId: $base->id,
                    description: "Base '" . ($base->name ?? 'Sin nombre') . "': " . implode(', ', $changeLog['descriptions']),
                    palletId: $pallet->id,
                    oldValues: ! empty($changeLog['old']) ? $changeLog['old'] : null,
                    newValues: ! empty($changeLog['new']) ? $changeLog['new'] : null,
                );
            }

            return ['base' => $base];
        });
    }

    private function buildBaseChangeLog(PalletBase $base, array $data): array
    {
        $oldValues = [];
        $newValues = [];
        $descriptions = [];

        if (isset($data['name']) && $base->name !== ($data['name'] ?? null)) {
            $oldValues['name'] = $base->name;
            $newValues['name'] = $data['name'] ?? null;
            $descriptions[] = "Nombre cambiado de '{$base->name}' a '{$newValues['name']}'";
        }

        if (isset($data['note']) && $base->note !== ($data['note'] ?? null)) {
            $oldValues['note'] = $base->note;
            $newValues['note'] = $data['note'] ?? null;
            $descriptions[] = 'Nota actualizada';
        }

        return ['old' => $oldValues, 'new' => $newValues, 'descriptions' => $descriptions];
    }

    private function syncBaseItems(Collection $palletOrderIds, PalletBase $base, array $items, array $oldItems, Collection $orderItemsMap, Collection $ordersMap, Collection $allocations): array
    {
        $itemsToSync = $oldItems;
        $removedItemIds = [];

        foreach ($items as $item) {
            $orderItem = $orderItemsMap->get($item['order_item_id']);

            if (! $orderItem || ! $palletOrderIds->contains($orderItem->order_id)) {
                return ['error' => [
                    'message' => 'El producto no pertenece a un pedido asociado a este pallet.',
                ]];
            }

            $order = $ordersMap->get($orderItem->order_id);

            if ($order && $order->status === 'done') {
                $oldQty = $oldItems[$item['order_item_id']]['qty'] ?? 0;
                if ($item['qty'] != $oldQty) {
                    return ['error' => [
                        'message' => "No se puede modificar la cantidad de '{$orderItem->description}' porque el pedido '{$order->code}' está finalizado.",
                        'order_item_id' => $item['order_item_id'],
                        'order_code' => $order->code,
                    ]];
                }

                continue;
            }

            if ($item['qty'] === 0) {
                if (isset($oldItems[$item['order_item_id']])) {
                    $removedItemIds[] = $item['order_item_id'];
                }
                unset($itemsToSync[$item['order_item_id']]);

                continue;
            }

            $totalAssigned = $allocations->get($item['order_item_id'], 0);
            $available = $orderItem->qty - $totalAssigned;

            if ($item['qty'] > $available) {
                return ['error' => [
                    'message' => "No se puede asignar {$item['qty']} unidades. Solo quedan {$available} disponibles de '{$orderItem->description}'.",
                    'order_item_id' => $item['order_item_id'],
                    'available' => $available,
                    'requested' => $item['qty'],
                ]];
            }

            $itemsToSync[$item['order_item_id']] = ['qty' => $item['qty']];
        }

        $base->orderItems()->sync($itemsToSync);

        return [
            'items' => $itemsToSync,
            'removed' => $removedItemIds,
        ];
    }

    private function logItemChanges(Pallet $pallet, PalletBase $base, array $itemsToSync, array $oldItems, array $removedItemIds, Collection $orderItemsMap): void
    {
        foreach ($itemsToSync as $orderItemId => $pivotData) {
            $orderItem = $orderItemsMap->get($orderItemId);
            if ($orderItem) {
                $oldQty = $oldItems[$orderItemId]['qty'] ?? 0;
                if ($oldQty != $pivotData['qty']) {
                    ActivityLogger::log(
                        action: 'item_base_qty_changed',
                        entityType: 'order_item',
                        entityId: $orderItemId,
                        description: "Cantidad de '{$orderItem->description}' en base '" . ($base->name ?? 'Sin nombre') . "' cambiada de {$oldQty} a {$pivotData['qty']}",
                        palletId: $pallet->id,
                        oldValues: ['base_id' => $base->id, 'qty' => $oldQty],
                        newValues: ['base_id' => $base->id, 'qty' => $pivotData['qty']],
                    );
                }
            }
        }

        foreach ($removedItemIds as $orderItemId) {
            $orderItem = $orderItemsMap->get($orderItemId);
            if ($orderItem) {
                $oldQty = $oldItems[$orderItemId]['qty'] ?? 0;
                ActivityLogger::log(
                    action: 'item_removed_from_base',
                    entityType: 'order_item',
                    entityId: $orderItemId,
                    description: "Producto '{$orderItem->description}' retirado de base '" . ($base->name ?? 'Sin nombre') . "' ({$oldQty} u.)",
                    palletId: $pallet->id,
                    oldValues: ['base_id' => $base->id, 'qty' => $oldQty],
                    newValues: ['base_id' => $base->id, 'qty' => 0],
                );
            }
        }
    }

    /**
     * Devuelve un mapa [order_item_id => total_qty_asignada_en_otras_bases].
     * Si $itemIds está vacío, retorna una colección vacía sin hacer query.
     */
    private function fetchAllocations(?PalletBase $base, Collection $itemIds, bool $lock = false): Collection
    {
        if ($itemIds->isEmpty()) {
            return collect();
        }

        $query = DB::table('pallet_base_order_items')
            ->whereIn('pallet_base_order_items.order_item_id', $itemIds)
            ->when($base, fn ($query) => $query->where('base_id', '!=', $base->id));

        if ($lock) {
            (clone $query)
                ->orderBy('order_item_id')
                ->orderBy('base_id')
                ->lockForUpdate()
                ->get();
        }

        return $query
            ->groupBy('pallet_base_order_items.order_item_id')
            ->selectRaw('pallet_base_order_items.order_item_id, SUM(pallet_base_order_items.qty) as total_qty')
            ->get()
            ->pluck('total_qty', 'order_item_id');
    }

    /**
     * Pre-fetcha OrderItems y Orders en 2 queries fijas.
     * Retorna [$orderItemsMap (keyBy id), $ordersMap (keyBy id)].
     */
    private function fetchOrderData(Collection $itemIds, bool $lock = false): array
    {
        $orderItemsQuery = OrderItem::whereIn('id', $itemIds)->orderBy('id');
        if ($lock) {
            $orderItemsQuery->lockForUpdate();
        }
        $orderItemsMap = $orderItemsQuery->get()->keyBy('id');
        $orderIds = $orderItemsMap->pluck('order_id')->unique()->values();
        $ordersQuery = Order::whereIn('id', $orderIds)->orderBy('id');
        if ($lock) {
            $ordersQuery->lockForUpdate();
        }
        $ordersMap = $ordersQuery->get()->keyBy('id');

        return [$orderItemsMap, $ordersMap];
    }
}
