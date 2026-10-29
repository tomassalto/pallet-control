<?php

namespace App\Http\Controllers\Api;

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
        $result = $this->orderItemService->update($item, $request->validated());

        if (isset($result['error'])) {
            return response()->json($result['error'], $result['status']);
        }

        return response()->json($result['item']);
    }
}
