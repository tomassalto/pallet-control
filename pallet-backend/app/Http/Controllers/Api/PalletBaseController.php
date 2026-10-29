<?php

namespace App\Http\Controllers\Api;

use App\Helpers\ActivityLogger;
use App\Http\Controllers\Controller;
use App\Http\Requests\AdjustPalletBaseItemRequest;
use App\Http\Requests\MigratePalletBaseRequest;
use App\Http\Requests\StorePalletBaseRequest;
use App\Models\OrderItem;
use App\Models\Pallet;
use App\Models\PalletBase;
use App\Services\PalletBaseService;
use Illuminate\Support\Facades\DB;

class PalletBaseController extends Controller
{
    public function __construct(
        private readonly PalletBaseService $palletBaseService
    ) {}

    public function index(Pallet $pallet)
    {
        $bases = $pallet->bases()->with(['photos', 'orderItems'])->latest()->get();

        return response()->json($bases);
    }

    public function store(StorePalletBaseRequest $request, Pallet $pallet)
    {
        $result = $this->palletBaseService->createBase($pallet, $request->validated());

        if (isset($result['error'])) {
            return response()->json($result['error'], $result['status'] ?? 422);
        }

        return response()->json($result['base']->load(['photos', 'orderItems']), 201);
    }

    public function update(StorePalletBaseRequest $request, Pallet $pallet, PalletBase $base)
    {
        $result = $this->palletBaseService->updateBase($pallet, $base, $request->validated());

        if (isset($result['error'])) {
            return response()->json($result['error'], $result['status'] ?? 422);
        }

        return response()->json($result['base']->load(['photos', 'orderItems']));
    }

    // POST /pallets/{pallet}/bases/{base}/migrate
    public function migrate(MigratePalletBaseRequest $request, Pallet $pallet, PalletBase $base)
    {
        $sourcePallet = $pallet;
        $sourceBase = $base;

        if ($sourceBase->pallet_id !== $sourcePallet->id) {
            return response()->json(['message' => 'Base no encontrada en este pallet'], 404);
        }

        if ($sourcePallet->status === 'done') {
            return response()->json(['message' => 'El pallet está finalizado. Reabrilo antes de migrar productos.'], 422);
        }

        $data = $request->validated();

        $orderItemIds = collect($data['items'])->pluck('order_item_id');

        $orderItemsMap = OrderItem::whereIn('id', $orderItemIds)->get()->keyBy('id');

        $sourceRecords = DB::table('pallet_base_order_items')
            ->where('base_id', $sourceBase->id)
            ->whereIn('order_item_id', $orderItemIds)
            ->get()
            ->keyBy('order_item_id');

        foreach ($data['items'] as $itemData) {
            $record = $sourceRecords->get($itemData['order_item_id']);
            $available = $record ? $record->qty : 0;
            if ($available < $itemData['qty']) {
                $item = $orderItemsMap->get($itemData['order_item_id']);

                return response()->json([
                    'message' => "Cantidad insuficiente de '{$item?->description}'. Disponible: {$available}, solicitado: {$itemData['qty']}.",
                ], 422);
            }
        }

        $result = DB::transaction(function () use ($data, $sourcePallet, $sourceBase, $sourceRecords, $orderItemsMap) {
            if (! empty($data['destination_pallet_id'])) {
                $destPallet = Pallet::findOrFail($data['destination_pallet_id']);
            } else {
                $today = now()->format('Ymd');
                $countToday = Pallet::whereDate('created_at', now()->toDateString())->count() + 1;
                $destPallet = Pallet::create([
                    'code' => sprintf('PAL-%s-%04d', $today, $countToday),
                    'status' => 'open',
                ]);
            }

            if (! empty($data['destination_base_id'])) {
                $destBase = PalletBase::findOrFail($data['destination_base_id']);
            } else {
                $nextNum = $destPallet->bases()->count() + 1;
                $destBase = $destPallet->bases()->create(['name' => "Base {$nextNum}"]);
            }

            $sourceName = ($sourcePallet->code . ' / ' . ($sourceBase->name ?? "Base #{$sourceBase->id}"));
            $destName = ($destPallet->code . ' / ' . ($destBase->name ?? "Base #{$destBase->id}"));

            foreach ($data['items'] as $itemData) {
                $orderItemId = $itemData['order_item_id'];
                $qtyToMove = $itemData['qty'];
                $sourceRecord = $sourceRecords->get($orderItemId);

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
                        'base_id' => $destBase->id,
                        'order_item_id' => $orderItemId,
                        'qty' => $qtyToMove,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                }

                $orderItem = $orderItemsMap->get($orderItemId);
                if ($orderItem && ! $destPallet->orders()->where('orders.id', $orderItem->order_id)->exists()) {
                    $destPallet->orders()->attach($orderItem->order_id);
                }

                ActivityLogger::log(
                    action: 'item_migrated',
                    entityType: 'order_item',
                    entityId: $orderItemId,
                    description: "Migrado '{$orderItem?->description}': {$qtyToMove} u. de [{$sourceName}] → [{$destName}]",
                    palletId: $sourcePallet->id,
                    oldValues: ['source' => $sourceName, 'qty' => $qtyToMove],
                    newValues: ['dest' => $destName, 'qty' => $qtyToMove],
                );
            }

            return [
                'destination_pallet_id' => $destPallet->id,
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
    public function adjustItem(AdjustPalletBaseItemRequest $request, Pallet $pallet, PalletBase $base)
    {
        if ($base->pallet_id !== $pallet->id) {
            return response()->json(['message' => 'Base no encontrada en este pallet'], 404);
        }

        if ($pallet->status === 'done') {
            return response()->json(['message' => 'El pallet está finalizado. Reabrilo antes de modificar sus bases.'], 422);
        }

        $data = $request->validated();

        $result = DB::transaction(function () use ($data, $pallet, $base) {
            // El bloqueo del item serializa todos sus ajustes, aunque estén en pallets distintos.
            $orderItem = OrderItem::query()
                ->lockForUpdate()
                ->findOrFail($data['order_item_id']);

            $belongsToPallet = $pallet->orders()
                ->whereKey($orderItem->order_id)
                ->exists();

            if (! $belongsToPallet) {
                return [
                    'error' => [
                        'message' => 'El producto no pertenece a un pedido asociado a este pallet.',
                    ],
                    'status' => 409,
                ];
            }

            $allocations = DB::table('pallet_base_order_items')
                ->where('order_item_id', $orderItem->id)
                ->orderBy('base_id')
                ->lockForUpdate()
                ->get();

            $oldQty = (int) ($allocations->firstWhere('base_id', $base->id)?->qty ?? 0);
            $allocatedElsewhere = (int) $allocations
                ->where('base_id', '!=', $base->id)
                ->sum('qty');
            $available = max(0, (int) $orderItem->qty - $allocatedElsewhere);

            if ($data['qty'] > $available) {
                return [
                    'error' => [
                        'message' => "Cantidad solicitada superior a la disponible. Disponible: {$available}, solicitado: {$data['qty']}.",
                        'available' => $available,
                        'requested' => $data['qty'],
                    ],
                    'status' => 409,
                ];
            }

            if ($data['qty'] === 0) {
                DB::table('pallet_base_order_items')
                    ->where('base_id', $base->id)
                    ->where('order_item_id', $orderItem->id)
                    ->delete();
            } else {
                DB::table('pallet_base_order_items')->updateOrInsert(
                    ['base_id' => $base->id, 'order_item_id' => $orderItem->id],
                    ['qty' => $data['qty'], 'updated_at' => now()]
                );
            }

            ActivityLogger::log(
                action: 'item_base_qty_adjusted',
                entityType: 'order_item',
                entityId: $orderItem->id,
                description: "Cantidad de '{$orderItem->description}' en base '" . ($base->name ?? 'Sin nombre') . "' ajustada de {$oldQty} a {$data['qty']}",
                palletId: $pallet->id,
                oldValues: ['base_id' => $base->id, 'qty' => $oldQty],
                newValues: ['base_id' => $base->id, 'qty' => $data['qty']],
            );

            return ['old_qty' => $oldQty];
        });

        if (isset($result['error'])) {
            return response()->json($result['error'], $result['status']);
        }

        return response()->json([
            'ok' => true,
            'old_qty' => $result['old_qty'],
            'new_qty' => $data['qty'],
        ]);
    }

    public function destroy(Pallet $pallet, PalletBase $base)
    {
        if ($base->pallet_id !== $pallet->id) {
            return response()->json(['message' => 'Base no encontrada'], 404);
        }

        $baseName = $base->name ?? 'Sin nombre';
        $baseId = $base->id;

        $base->delete();

        ActivityLogger::log(
            action: 'base_deleted',
            entityType: 'pallet_base',
            entityId: $baseId,
            description: "Base eliminada: '{$baseName}'",
            palletId: $pallet->id,
            oldValues: ['name' => $baseName, 'note' => $base->note],
        );

        return response()->json(['message' => 'Base eliminada'], 200);
    }
}
