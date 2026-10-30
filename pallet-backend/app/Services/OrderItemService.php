<?php

namespace App\Services;

use App\Helpers\ActivityLogger;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

class OrderItemService
{
    public function update(OrderItem $item, array $data): array
    {
        return DB::transaction(function () use ($item, $data) {
            $lockedItem = OrderItem::query()->lockForUpdate()->findOrFail($item->id);
            $allocations = DB::table('pallet_base_order_items')
                ->where('order_item_id', $lockedItem->id)
                ->orderBy('base_id')
                ->lockForUpdate()
                ->get();
            $assignedQty = (int) $allocations->sum('qty');

            if (isset($data['qty']) && $data['qty'] < $assignedQty) {
                return [
                    'error' => [
                        'message' => "La cantidad no puede ser menor que las {$assignedQty} unidades ya asignadas.",
                        'assigned' => $assignedQty,
                        'requested' => $data['qty'],
                    ],
                    'status' => 409,
                ];
            }

            $finalQty = (int) ($data['qty'] ?? $lockedItem->qty);
            $finalDoneQty = (int) ($data['done_qty'] ?? $lockedItem->done_qty);
            if ($finalDoneQty > $finalQty) {
                return [
                    'error' => [
                        'message' => 'La cantidad completada no puede superar la cantidad pedida.',
                        'qty' => $finalQty,
                        'done_qty' => $finalDoneQty,
                    ],
                    'status' => 409,
                ];
            }

            [$oldValues, $newValues, $descriptions] = $this->buildUpdateLog($lockedItem, $data);
            $lockedItem->update($data);

            if ($descriptions !== []) {
                $action = isset($data['status']) && $data['status'] === 'removed'
                    ? 'item_removed'
                    : (isset($data['qty']) ? 'item_quantity_changed' : 'item_status_changed');
                $palletId = DB::table('order_pallet')
                    ->where('order_id', $lockedItem->order_id)
                    ->orderBy('pallet_id')
                    ->value('pallet_id');

                ActivityLogger::log(
                    action: $action,
                    entityType: 'order_item',
                    entityId: $lockedItem->id,
                    description: "{$lockedItem->description} (EAN: {$lockedItem->ean}): " . implode(', ', $descriptions),
                    palletId: $palletId ? (int) $palletId : null,
                    oldValues: $oldValues,
                    newValues: $newValues,
                    orderId: $lockedItem->order_id,
                );
            }

            return ['item' => $lockedItem];
        });
    }

    public function addToOrder(Order $order, array $data): array
    {
        $resolvedProduct = $this->resolveProduct($data);
        if (isset($resolvedProduct['error'])) {
            return $resolvedProduct;
        }

        $ean = $resolvedProduct['ean'];
        $description = $resolvedProduct['description'];

        try {
            return DB::transaction(function () use ($order, $data, $ean, $description) {
                $lockedOrder = Order::query()->lockForUpdate()->findOrFail($order->id);
                $existingItem = OrderItem::query()
                    ->where('order_id', $lockedOrder->id)
                    ->where('ean', $ean)
                    ->first();

                if ($existingItem) {
                    return $this->duplicateResult($existingItem);
                }

                $item = OrderItem::create([
                    'order_id' => $lockedOrder->id,
                    'ean' => $ean,
                    'ean_last4' => substr($ean, -4),
                    'description' => $description,
                    'qty' => $data['qty'],
                    'status' => 'pending',
                    'done_qty' => 0,
                ]);

                $palletId = $lockedOrder->pallets()->value('pallets.id');
                ActivityLogger::log(
                    action: 'item_added',
                    entityType: 'order_item',
                    entityId: $item->id,
                    description: "Producto agregado: {$description} (EAN: {$ean}) - Cantidad: {$data['qty']}",
                    palletId: $palletId,
                    newValues: ['ean' => $ean, 'description' => $description, 'qty' => $data['qty']],
                    orderId: $lockedOrder->id,
                );

                return ['item' => $item];
            });
        } catch (QueryException $exception) {
            if (! $this->isOrderEanUniqueViolation($exception)) {
                throw $exception;
            }

            $existingItem = OrderItem::query()
                ->where('order_id', $order->id)
                ->where('ean', $ean)
                ->first();

            return $existingItem
                ? $this->duplicateResult($existingItem)
                : ['error' => ['message' => 'Este producto ya está en el pedido.'], 'status' => 409];
        }
    }

    private function resolveProduct(array $data): array
    {
        if (! empty($data['ean'])) {
            $productName = Product::query()->where('ean', $data['ean'])->value('name');

            return [
                'ean' => $data['ean'],
                'description' => $productName ?? 'SIN DESCRIPCIÓN',
            ];
        }

        $last4 = str_pad($data['last4'], 4, '0', STR_PAD_LEFT);
        $matches = Product::query()->where('ean_last4', $last4)->limit(10)->get();

        if ($matches->isEmpty()) {
            return ['error' => ['message' => 'No hay producto con esos últimos 4.'], 'status' => 404];
        }
        if ($matches->count() > 1) {
            return [
                'error' => [
                    'message' => 'Hay más de un producto con esos últimos 4. Usá EAN completo.',
                    'candidates' => $matches->map(fn (Product $product) => [
                        'ean' => $product->ean,
                        'name' => $product->name,
                    ]),
                ],
                'status' => 409,
            ];
        }

        $product = $matches->firstOrFail();

        return ['ean' => $product->ean, 'description' => $product->name];
    }

    private function buildUpdateLog(OrderItem $item, array $data): array
    {
        $oldValues = [];
        $newValues = [];
        $descriptions = [];

        if (isset($data['qty']) && $item->qty != $data['qty']) {
            $oldValues['qty'] = $item->qty;
            $newValues['qty'] = $data['qty'];
            $descriptions[] = "Cantidad modificada de {$item->qty} a {$data['qty']}";
        }

        if (isset($data['status']) && $item->status != $data['status']) {
            $oldValues['status'] = $item->status;
            $newValues['status'] = $data['status'];
            $statusLabels = ['pending' => 'Pendiente', 'done' => 'Listo', 'removed' => 'Quitado'];
            $descriptions[] = "Estado cambiado de '{$statusLabels[$item->status]}' a '{$statusLabels[$data['status']]}'";
        }

        if (isset($data['done_qty']) && $item->done_qty != $data['done_qty']) {
            $oldValues['done_qty'] = $item->done_qty;
            $newValues['done_qty'] = $data['done_qty'];
            $descriptions[] = "Cantidad completada modificada de {$item->done_qty} a {$data['done_qty']}";
        }

        return [$oldValues, $newValues, $descriptions];
    }

    private function duplicateResult(OrderItem $item): array
    {
        return [
            'error' => [
                'message' => 'Este producto ya está en el pedido.',
                'existing_item' => $item,
            ],
            'status' => 409,
        ];
    }

    private function isOrderEanUniqueViolation(QueryException $exception): bool
    {
        $sqlState = $exception->errorInfo[0] ?? (string) $exception->getCode();
        $message = $exception->getMessage();

        return in_array($sqlState, ['23000', '23505'], true)
            && (str_contains($message, 'order_items_order_id_ean_unique')
                || str_contains($message, 'order_items.order_id, order_items.ean'));
    }
}
