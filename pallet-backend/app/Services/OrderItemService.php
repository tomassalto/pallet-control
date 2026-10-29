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
