<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Helpers\ActivityLogger;
use App\Models\Pallet;
use App\Models\PalletBase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\DB;

class PalletBaseController extends Controller
{
    public function index(Pallet $pallet)
    {
        $bases = $pallet->bases()->with(['photos', 'orderItems'])->latest()->get();

        return response()->json($bases);
    }

    public function store(Request $request, Pallet $pallet)
    {
        $data = $request->validate([
            'name' => ['nullable', 'string', 'max:255'],
            'note' => ['nullable', 'string', 'max:2000'],
            'items' => ['nullable', 'array'],
            'items.*.order_item_id' => ['required', 'integer', 'exists:order_items,id'],
            'items.*.qty' => ['required', 'integer', 'min:1'],
        ]);

        $base = PalletBase::create([
            'pallet_id' => $pallet->id,
            'name' => $data['name'] ?? null,
            'note' => $data['note'] ?? null,
        ]);

        // Asociar items si se enviaron
        if (!empty($data['items'])) {
            // Pre-fetch todo en 3 queries fijas — sin N+1 dentro del loop
            $pallet->loadMissing('orders');
            $requestedItemIds = collect($data['items'])->pluck('order_item_id');

            $orderItemsMap = \App\Models\OrderItem::whereIn('id', $requestedItemIds)
                ->get()->keyBy('id');

            $orderIds = $orderItemsMap->pluck('order_id')->unique()->values();
            $ordersMap = \App\Models\Order::whereIn('id', $orderIds)
                ->get()->keyBy('id');

            // Una sola query agrupa todas las cantidades ya asignadas en otras bases
            $allocations = DB::table('pallet_base_order_items')
                ->join('pallet_bases', 'pallet_bases.id', '=', 'pallet_base_order_items.base_id')
                ->where('pallet_bases.pallet_id', $pallet->id)
                ->where('pallet_bases.id', '!=', $base->id)
                ->whereIn('pallet_base_order_items.order_item_id', $requestedItemIds)
                ->groupBy('pallet_base_order_items.order_item_id')
                ->selectRaw('pallet_base_order_items.order_item_id, SUM(pallet_base_order_items.qty) as total_qty')
                ->get()
                ->pluck('total_qty', 'order_item_id');

            $itemsToSync = [];
            foreach ($data['items'] as $item) {
                // Verificar que el order_item pertenece a un pedido del pallet
                $orderItem = $orderItemsMap->get($item['order_item_id']);
                if ($orderItem && $pallet->orders->contains('id', $orderItem->order_id)) {
                    // Verificar si el pedido está finalizado
                    $order = $ordersMap->get($orderItem->order_id);
                    if ($order && $order->status === 'done') {
                        return response()->json([
                            'message' => "No se puede asignar '{$orderItem->description}' porque el pedido '{$order->code}' está finalizado.",
                            'order_item_id' => $item['order_item_id'],
                            'order_code' => $order->code,
                        ], 422);
                    }

                    $totalAssigned = $allocations->get($item['order_item_id'], 0);
                    $available = $orderItem->qty - $totalAssigned;

                    if ($item['qty'] > $available) {
                        return response()->json([
                            'message' => "No se puede asignar {$item['qty']} unidades. Solo quedan {$available} disponibles de '{$orderItem->description}'.",
                            'order_item_id' => $item['order_item_id'],
                            'available' => $available,
                            'requested' => $item['qty'],
                        ], 422);
                    }

                    $itemsToSync[$item['order_item_id']] = ['qty' => $item['qty']];
                }
            }
            $base->orderItems()->sync($itemsToSync);

            // Registrar logs — reusar $orderItemsMap (0 queries extra)
            foreach ($itemsToSync as $orderItemId => $pivotData) {
                $orderItem = $orderItemsMap->get($orderItemId);
                if ($orderItem) {
                    ActivityLogger::log(
                        'item_assigned_to_base',
                        'order_item',
                        $orderItemId,
                        "Producto '{$orderItem->description}' asignado a base '" . ($base->name ?? 'Sin nombre') . "' - Cantidad: {$pivotData['qty']}",
                        $pallet->id,
                        null,
                        ['base_id' => $base->id, 'base_name' => $base->name, 'qty' => $pivotData['qty']]
                    );
                }
            }
        }

        ActivityLogger::log(
            'base_created',
            'pallet_base',
            $base->id,
            "Base creada: '" . ($base->name ?? 'Sin nombre') . "'",
            $pallet->id,
            null,
            ['name' => $base->name, 'note' => $base->note]
        );

        return response()->json($base->load(['photos', 'orderItems']), 201);
    }

    public function update(Request $request, Pallet $pallet, PalletBase $base)
    {
        // Verificar que la base pertenece al pallet
        if ($base->pallet_id !== $pallet->id) {
            return response()->json(['message' => 'Base no encontrada'], 404);
        }

        $data = $request->validate([
            'name' => ['nullable', 'string', 'max:255'],
            'note' => ['nullable', 'string', 'max:2000'],
            'items' => ['nullable', 'array'],
            'items.*.order_item_id' => ['required', 'integer', 'exists:order_items,id'],
            'items.*.qty' => ['required', 'integer', 'min:1'],
        ]);

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
            $descriptions[] = "Nota actualizada";
        }

        $base->update([
            'name' => $data['name'] ?? $base->name,
            'note' => $data['note'] ?? $base->note,
        ]);

        // Actualizar items si se enviaron
        if (isset($data['items'])) {
            $oldItems = $base->orderItems()->get()->mapWithKeys(function ($item) {
                return [$item->id => ['qty' => $item->pivot->qty]];
            })->toArray();

            // Pre-fetch todo en 3 queries — sin N+1 dentro de los loops
            $pallet->loadMissing('orders');
            $requestedItemIds = collect($data['items'])->pluck('order_item_id');
            $allItemIds = $requestedItemIds->merge(array_keys($oldItems))->unique()->values();

            $orderItemsMap = \App\Models\OrderItem::whereIn('id', $allItemIds)
                ->get()->keyBy('id');

            $orderIds = $orderItemsMap->pluck('order_id')->unique()->values();
            $ordersMap = \App\Models\Order::whereIn('id', $orderIds)
                ->get()->keyBy('id');

            // Una sola query agrupa cantidades ya asignadas en otras bases (solo items solicitados)
            $allocations = $requestedItemIds->isNotEmpty()
                ? DB::table('pallet_base_order_items')
                    ->join('pallet_bases', 'pallet_bases.id', '=', 'pallet_base_order_items.base_id')
                    ->where('pallet_bases.pallet_id', $pallet->id)
                    ->where('pallet_bases.id', '!=', $base->id)
                    ->whereIn('pallet_base_order_items.order_item_id', $requestedItemIds)
                    ->groupBy('pallet_base_order_items.order_item_id')
                    ->selectRaw('pallet_base_order_items.order_item_id, SUM(pallet_base_order_items.qty) as total_qty')
                    ->get()
                    ->pluck('total_qty', 'order_item_id')
                : collect();

            // Incluir items existentes para no perderlos
            $itemsToSync = $oldItems;

            foreach ($data['items'] as $item) {
                // Verificar que el order_item pertenece a un pedido del pallet
                $orderItem = $orderItemsMap->get($item['order_item_id']);
                if ($orderItem && $pallet->orders->contains('id', $orderItem->order_id)) {
                    // Verificar si el pedido está finalizado
                    $order = $ordersMap->get($orderItem->order_id);
                    if ($order && $order->status === 'done') {
                        // Si el item ya existe en la base y la cantidad no cambió, permitir (no modificar)
                        $oldQty = $oldItems[$item['order_item_id']]['qty'] ?? 0;
                        if ($item['qty'] != $oldQty) {
                            return response()->json([
                                'message' => "No se puede modificar la cantidad de '{$orderItem->description}' porque el pedido '{$order->code}' está finalizado.",
                                'order_item_id' => $item['order_item_id'],
                                'order_code' => $order->code,
                            ], 422);
                        }
                        // Si la cantidad no cambió, mantener el item sin modificar
                        continue;
                    }

                    $totalAssigned = $allocations->get($item['order_item_id'], 0);
                    // Si el item ya existe en esta base, incluir su cantidad actual en el cálculo
                    $currentQtyInBase = $oldItems[$item['order_item_id']]['qty'] ?? 0;
                    $available = $orderItem->qty - $totalAssigned + $currentQtyInBase;

                    if ($item['qty'] > $available) {
                        return response()->json([
                            'message' => "No se puede asignar {$item['qty']} unidades. Solo quedan {$available} disponibles de '{$orderItem->description}'.",
                            'order_item_id' => $item['order_item_id'],
                            'available' => $available,
                            'requested' => $item['qty'],
                        ], 422);
                    }

                    // Agregar o actualizar el item
                    $itemsToSync[$item['order_item_id']] = ['qty' => $item['qty']];
                }
            }
            $base->orderItems()->sync($itemsToSync);

            // Registrar logs — reusar $orderItemsMap (0 queries extra)
            foreach ($itemsToSync as $orderItemId => $pivotData) {
                $orderItem = $orderItemsMap->get($orderItemId);
                if ($orderItem) {
                    $oldQty = $oldItems[$orderItemId]['qty'] ?? 0;
                    if ($oldQty != $pivotData['qty']) {
                        ActivityLogger::log(
                            'item_base_qty_changed',
                            'order_item',
                            $orderItemId,
                            "Cantidad de '{$orderItem->description}' en base '" . ($base->name ?? 'Sin nombre') . "' cambiada de {$oldQty} a {$pivotData['qty']}",
                            $pallet->id,
                            ['base_id' => $base->id, 'qty' => $oldQty],
                            ['base_id' => $base->id, 'qty' => $pivotData['qty']]
                        );
                    }
                }
            }
        }

        // Registrar log si hubo cambios en nombre o nota
        if (!empty($descriptions)) {
            ActivityLogger::log(
                'base_updated',
                'pallet_base',
                $base->id,
                "Base '" . ($base->name ?? 'Sin nombre') . "': " . implode(', ', $descriptions),
                $pallet->id,
                !empty($oldValues) ? $oldValues : null,
                !empty($newValues) ? $newValues : null
            );
        }

        return response()->json($base->load(['photos', 'orderItems']));
    }

    public function destroy(Pallet $pallet, PalletBase $base)
    {
        // Verificar que la base pertenece al pallet
        if ($base->pallet_id !== $pallet->id) {
            return response()->json(['message' => 'Base no encontrada'], 404);
        }

        $baseName = $base->name ?? 'Sin nombre';
        $baseId = $base->id;

        $base->delete();

        ActivityLogger::log(
            'base_deleted',
            'pallet_base',
            $baseId,
            "Base eliminada: '{$baseName}'",
            $pallet->id,
            ['name' => $baseName, 'note' => $base->note],
            null
        );

        return response()->json(['message' => 'Base eliminada'], 200);
    }
}
