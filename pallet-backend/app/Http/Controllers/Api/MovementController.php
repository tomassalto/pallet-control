<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Movement;
use App\Models\Order;
use App\Models\Pallet;
use App\Models\Product;
use Illuminate\Http\Request;

class MovementController extends Controller
{
    public function index(Pallet $pallet)
    {
        return Movement::with(['product', 'order.customer'])
            ->where('pallet_id', $pallet->id)
            ->orderByDesc('id')
            ->paginate(50);
    }

    public function store(Request $request, Pallet $pallet)
    {
        $data = $request->validate([
            'order_id' => 'required|integer|exists:orders,id',
            'ean' => 'required|string|max:32',
            'type' => 'required|in:ADD,REMOVE,ADJUST',
            'qty'  => 'required|integer|min:1',
            'note' => 'nullable|string',
        ]);

        if ($pallet->status === 'done') {
            return response()->json(['message' => 'Pallet is done/closed'], 409);
        }

        $order = Order::findOrFail($data['order_id']);

        // Asegura relación order<->pallet (por si te olvidaste de attach)
        $pallet->orders()->syncWithoutDetaching([$order->id]);

        $product = Product::where('ean', $data['ean'])->first();
        if (!$product) {
            return response()->json(['message' => 'Unknown EAN', 'ean' => $data['ean']], 404);
        }

        $qtySigned = $data['qty'];

        if ($data['type'] === 'REMOVE') {
            $qtySigned = -abs($qtySigned);
        }

        // Para MVP: ADJUST funciona como ADD pero con etiqueta distinta.
        // Si después querés ADJUST +/- real, cambiamos la validación para permitir negativos.

        $movement = Movement::create([
            'pallet_id' => $pallet->id,
            'order_id' => $order->id,
            'product_id' => $product->id,
            'type' => $data['type'],
            'qty' => $qtySigned,
            'note' => $data['note'] ?? null,
            'user_id' => null,
        ]);

        return response()->json($movement->load(['product', 'order.customer']), 201);
    }
}
