<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Helpers\ActivityLogger;
use App\Models\OrderItem;
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

    // POST /pallets/{pallet}/bases/{base}/migrate
    public function migrate(Request $request, Pallet $pallet, PalletBase $base)
    {
        $sourcePallet = $pallet;
        $sourceBase   = $base;

        if ($sourceBase->pallet_id !== $sourcePallet->id) {
            return response()->json(['message' => 'Base no encontrada en este pallet'], 404);
        }

        if ($sourcePallet->status === 'done') {
            return response()->json(['message' => 'El pallet está finalizado. Reabrilo antes de migrar productos.'], 422);
        }

        $data = $request->validate([
            'items'                  => ['required', 'array', 'min:1'],
            'items.*.order_item_id'  => ['required', 'integer', 'exists:order_items,id'],
            'items.*.qty'            => ['required', 'integer', 'min:1'],
            'destination_pallet_id'  => ['nullable', 'integer', 'exists:pallets,id'],
            'destination_base_id'    => ['nullable', 'integer', 'exists:pallet_bases,id'],
        ]);

        $orderItemIds = collect($data['items'])->pluck('order_item_id');

        // Pre-fetch order items y registros origen
        $orderItemsMap = OrderItem::whereIn('id', $orderItemIds)->get()->keyBy('id');

        $sourceRecords = DB::table('pallet_base_order_items')
            ->where('base_id', $sourceBase->id)
            ->whereIn('order_item_id', $orderItemIds)
            ->get()
            ->keyBy('order_item_id');

        // Validar disponibilidad antes de tocar la DB
        foreach ($data['items'] as $itemData) {
            $record    = $sourceRecords->get($itemData['order_item_id']);
            $available = $record ? $record->qty : 0;
            if ($available < $itemData['qty']) {
                $item = $orderItemsMap->get($itemData['order_item_id']);
                return response()->json([
                    'message' => "Cantidad insuficiente de '{$item?->description}'. Disponible: {$available}, solicitado: {$itemData['qty']}.",
                ], 422);
            }
        }

        $result = DB::transaction(function () use ($data, $sourcePallet, $sourceBase, $sourceRecords, $orderItemsMap) {
            // Resolver pallet destino
            if (!empty($data['destination_pallet_id'])) {
                $destPallet = Pallet::findOrFail($data['destination_pallet_id']);
            } else {
                // Crear pallet nuevo con el mismo patrón de código
                $today      = now()->format('Ymd');
                $countToday = Pallet::whereDate('created_at', now()->toDateString())->count() + 1;
                $destPallet = Pallet::create([
                    'code'   => sprintf('PAL-%s-%04d', $today, $countToday),
                    'status' => 'open',
                ]);
            }

            // Resolver base destino
            if (!empty($data['destination_base_id'])) {
                $destBase = PalletBase::findOrFail($data['destination_base_id']);
            } else {
                $nextNum  = $destPallet->bases()->count() + 1;
                $destBase = $destPallet->bases()->create(['name' => "Base {$nextNum}"]);
            }

            $sourceName = ($sourcePallet->code . ' / ' . ($sourceBase->name ?? "Base #{$sourceBase->id}"));
            $destName   = ($destPallet->code   . ' / ' . ($destBase->name   ?? "Base #{$destBase->id}"));

            foreach ($data['items'] as $itemData) {
                $orderItemId = $itemData['order_item_id'];
                $qtyToMove   = $itemData['qty'];
                $sourceRecord = $sourceRecords->get($orderItemId);

                // Reducir en origen
                $newSourceQty = $sourceRecord->qty - $qtyToMove;
                if ($newSourceQty === 0) {
                    DB::table('pallet_base_order_items')
                        ->where('base_id', $sourceBase->id)
                        ->where('order_item_id', $orderItemId)
                        ->delete();
                } else {
                    DB::table('pallet_base_order_items')
                        ->where('base_id', $sourceBase->id)
                        ->where('order_item_id', $orderItemId)
                        ->update(['qty' => $newSourceQty, 'updated_at' => now()]);
                }

                // Sumar en destino
                $destRecord = DB::table('pallet_base_order_items')
                    ->where('base_id', $destBase->id)
                    ->where('order_item_id', $orderItemId)
                    ->first();

                if ($destRecord) {
                    DB::table('pallet_base_order_items')
                        ->where('base_id', $destBase->id)
                        ->where('order_item_id', $orderItemId)
                        ->update(['qty' => $destRecord->qty + $qtyToMove, 'updated_at' => now()]);
                } else {
                    DB::table('pallet_base_order_items')->insert([
                        'base_id'       => $destBase->id,
                        'order_item_id' => $orderItemId,
                        'qty'           => $qtyToMove,
                        'created_at'    => now(),
                        'updated_at'    => now(),
                    ]);
                }

                // Asociar pedido al pallet destino si no está vinculado
                $orderItem = $orderItemsMap->get($orderItemId);
                if ($orderItem && !$destPallet->orders()->where('orders.id', $orderItem->order_id)->exists()) {
                    $destPallet->orders()->attach($orderItem->order_id);
                }

                // Log
                ActivityLogger::log(
                    'item_migrated',
                    'order_item',
                    $orderItemId,
                    "Migrado '{$orderItem?->description}': {$qtyToMove} u. de [{$sourceName}] → [{$destName}]",
                    $sourcePallet->id,
                    ['source' => $sourceName, 'qty' => $qtyToMove],
                    ['dest'   => $destName,   'qty' => $qtyToMove],
                );
            }

            return [
                'destination_pallet_id'   => $destPallet->id,
                'destination_pallet_code' => $destPallet->code,
            ];
        });

        return response()->json([
            'message' => 'Productos migrados correctamente',
            ...$result,
        ]);
    }

    // PATCH /pallets/{pallet}/bases/{base}/adjust-item
    // Ajusta la qty de UN item en UNA base (usado para resolver conflictos de cantidad)
    public function adjustItem(Request $request, Pallet $pallet, PalletBase $base)
    {
        if ($base->pallet_id !== $pallet->id) {
            return response()->json(['message' => 'Base no encontrada en este pallet'], 404);
        }

        if ($pallet->status === 'done') {
            return response()->json(['message' => 'El pallet está finalizado. Reabrilo antes de modificar sus bases.'], 422);
        }

        $data = $request->validate([
            'order_item_id' => ['required', 'integer', 'exists:order_items,id'],
            'qty'           => ['required', 'integer', 'min:0'],
        ]);

        $orderItem = OrderItem::findOrFail($data['order_item_id']);

        $oldRecord = DB::table('pallet_base_order_items')
            ->where('base_id', $base->id)
            ->where('order_item_id', $data['order_item_id'])
            ->first();

        $oldQty = $oldRecord?->qty ?? 0;

        if ($data['qty'] === 0) {
            DB::table('pallet_base_order_items')
                ->where('base_id', $base->id)
                ->where('order_item_id', $data['order_item_id'])
                ->delete();
        } else {
            DB::table('pallet_base_order_items')->updateOrInsert(
                ['base_id' => $base->id, 'order_item_id' => $data['order_item_id']],
                ['qty' => $data['qty'], 'updated_at' => now()]
            );
        }

        ActivityLogger::log(
            'item_base_qty_adjusted',
            'order_item',
            $orderItem->id,
            "Cantidad de '{$orderItem->description}' en base '" . ($base->name ?? 'Sin nombre') . "' ajustada de {$oldQty} a {$data['qty']}",
            $pallet->id,
            ['base_id' => $base->id, 'qty' => $oldQty],
            ['base_id' => $base->id, 'qty' => $data['qty']]
        );

        return response()->json(['ok' => true, 'old_qty' => $oldQty, 'new_qty' => $data['qty']]);
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
