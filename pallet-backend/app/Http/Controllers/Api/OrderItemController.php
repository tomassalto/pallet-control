<?php

namespace App\Http\Controllers\Api;

use App\Helpers\ActivityLogger;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreOrderItemRequest;
use App\Http\Requests\UpdateOrderItemRequest;
use App\Models\Order;
use App\Models\OrderItem;
use App\Services\OrderItemService;

class OrderItemController extends Controller
{
    public function __construct(
        private readonly OrderItemService $orderItemService
    ) {}

    // POST /orders/{order}/items
    public function store(StoreOrderItemRequest $request, Order $order)
    {
        $data = $request->validated();

        if (empty($data['ean']) && empty($data['last4'])) {
            return response()->json(['message' => 'Debés enviar ean o last4.'], 422);
        }

        $result = $this->orderItemService->addToOrder($order, $data);

        if (isset($result['error'])) {
            return response()->json($result['error'], $result['status']);
        }

        return response()->json($result['item'], 201);
    }

    // PATCH /order-items/{item}
    public function update(UpdateOrderItemRequest $request, OrderItem $item)
    {
        $data = $request->validated();

        $oldValues = [];
        $newValues = [];
        $descriptions = [];

        // Obtener pallet_id si el pedido está asociado a un pallet
        $palletId = $item->order->pallets()->first()?->id;

        if (isset($data['qty']) && $item->qty != $data['qty']) {
            $oldValues['qty'] = $item->qty;
            $newValues['qty'] = $data['qty'];
            $descriptions[] = "Cantidad modificada de {$item->qty} a {$data['qty']}";
        }

        if (isset($data['status']) && $item->status != $data['status']) {
            $oldValues['status'] = $item->status;
            $newValues['status'] = $data['status'];

            $statusLabels = [
                'pending' => 'Pendiente',
                'done' => 'Listo',
                'removed' => 'Quitado',
            ];
            $oldLabel = $statusLabels[$item->status] ?? $item->status;
            $newLabel = $statusLabels[$data['status']] ?? $data['status'];
            $descriptions[] = "Estado cambiado de '{$oldLabel}' a '{$newLabel}'";
        }

        if (isset($data['done_qty']) && $item->done_qty != $data['done_qty']) {
            $oldValues['done_qty'] = $item->done_qty;
            $newValues['done_qty'] = $data['done_qty'];
            $descriptions[] = "Cantidad completada modificada de {$item->done_qty} a {$data['done_qty']}";
        }

        $item->update($data);

        // Registrar log si hubo cambios
        if (! empty($descriptions)) {
            $action = isset($data['status']) && $data['status'] === 'removed' ? 'item_removed' : (isset($data['qty']) ? 'item_quantity_changed' : 'item_status_changed');

            ActivityLogger::log(
                action: $action,
                entityType: 'order_item',
                entityId: $item->id,
                description: "{$item->description} (EAN: {$item->ean}): " . implode(', ', $descriptions),
                palletId: $palletId,
                oldValues: ! empty($oldValues) ? $oldValues : null,
                newValues: ! empty($newValues) ? $newValues : null,
                orderId: $item->order_id,
            );
        }

        return response()->json($item);
    }
}
