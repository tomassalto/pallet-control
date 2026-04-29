<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\UpdateOrderItemRequest;
use App\Helpers\ActivityLogger;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use Illuminate\Http\Request;

class OrderItemController extends Controller
{
    // POST /orders/{order}/items
    public function store(Request $request, Order $order)
    {
        $data = $request->validate([
            'qty' => ['required', 'integer', 'min:1'],
            'ean' => ['nullable', 'regex:/^\d{8,15}$/'],
            'last4' => ['nullable', 'string', 'max:4'],
        ]);

        if (empty($data['ean']) && empty($data['last4'])) {
            return response()->json(['message' => 'Debés enviar ean o last4.'], 422);
        }

        // Normalizar last4: solo dígitos y padding a 4 caracteres
        if (!empty($data['last4'])) {
            $data['last4'] = preg_replace('/\D+/', '', $data['last4']);
            $data['last4'] = str_pad($data['last4'], 4, '0', STR_PAD_LEFT);

            if (strlen($data['last4']) !== 4) {
                return response()->json(['message' => 'Los últimos 4 deben ser números.'], 422);
            }
        }

        if (!empty($data['ean'])) {
            $ean = $data['ean'];
            $last4 = substr($ean, -4);

            // Verificar si ya existe un item con este EAN en el pedido
            $existingItem = OrderItem::where('order_id', $order->id)
                ->where('ean', $ean)
                ->first();

            if ($existingItem) {
                return response()->json([
                    'message' => 'Este producto ya está en el pedido.',
                    'existing_item' => $existingItem,
                ], 409);
            }

            $product = Product::where('ean', $ean)->first();
            $desc = $product?->name ?? 'SIN DESCRIPCIÓN';

            $item = OrderItem::create([
                'order_id' => $order->id,
                'ean' => $ean,
                'ean_last4' => $last4,
                'description' => $desc,
                'qty' => $data['qty'],
                'status' => 'pending',
                'done_qty' => 0,
            ]);

            // Obtener pallet_id si el pedido está asociado a un pallet
            $palletId = $order->pallets()->first()?->id;

            ActivityLogger::log(
                action: 'item_added',
                entityType: 'order_item',
                entityId: $item->id,
                description: "Producto agregado: {$desc} (EAN: {$ean}) - Cantidad: {$data['qty']}",
                palletId: $palletId,
                newValues: ['ean' => $ean, 'description' => $desc, 'qty' => $data['qty']],
                orderId: $order->id,
            );

            return response()->json($item, 201);
        }

        // last4 - ya está normalizado arriba
        $matches = Product::where('ean_last4', $data['last4'])->limit(10)->get();

        if ($matches->count() === 0) {
            return response()->json(['message' => 'No hay producto con esos últimos 4.'], 404);
        }

        if ($matches->count() > 1) {
            return response()->json([
                'message' => 'Hay más de un producto con esos últimos 4. Usá EAN completo.',
                'candidates' => $matches->map(fn($p) => ['ean' => $p->ean, 'name' => $p->name]),
            ], 409);
        }

        $p = $matches->first();

        // Verificar si ya existe un item con este EAN en el pedido
        $existingItem = OrderItem::where('order_id', $order->id)
            ->where('ean', $p->ean)
            ->first();

        if ($existingItem) {
            return response()->json([
                'message' => 'Este producto ya está en el pedido.',
                'existing_item' => $existingItem,
            ], 409);
        }

        $item = OrderItem::create([
            'order_id' => $order->id,
            'ean' => $p->ean,
            'ean_last4' => $p->ean_last4,
            'description' => $p->name,
            'qty' => $data['qty'],
            'status' => 'pending',
            'done_qty' => 0,
        ]);

        // Obtener pallet_id si el pedido está asociado a un pallet
        $palletId = $order->pallets()->first()?->id;

        ActivityLogger::log(
            action: 'item_added',
            entityType: 'order_item',
            entityId: $item->id,
            description: "Producto agregado: {$p->name} (EAN: {$p->ean}) - Cantidad: {$data['qty']}",
            palletId: $palletId,
            newValues: ['ean' => $p->ean, 'description' => $p->name, 'qty' => $data['qty']],
            orderId: $order->id,
        );

        return response()->json($item, 201);
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
                'removed' => 'Quitado'
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
        if (!empty($descriptions)) {
            $action = isset($data['status']) && $data['status'] === 'removed' ? 'item_removed' : (isset($data['qty']) ? 'item_quantity_changed' : 'item_status_changed');

            ActivityLogger::log(
                action: $action,
                entityType: 'order_item',
                entityId: $item->id,
                description: "{$item->description} (EAN: {$item->ean}): " . implode(', ', $descriptions),
                palletId: $palletId,
                oldValues: !empty($oldValues) ? $oldValues : null,
                newValues: !empty($newValues) ? $newValues : null,
                orderId: $item->order_id,
            );
        }

        return response()->json($item);
    }
}
