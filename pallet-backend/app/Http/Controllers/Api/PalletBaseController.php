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
use App\Services\StorageCleanupService;
use Illuminate\Support\Facades\DB;

class PalletBaseController extends Controller
{
    public function __construct(
        private readonly PalletBaseService $palletBaseService,
        private readonly StorageCleanupService $storageCleanup,
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
        $result = $this->palletBaseService->migrateBase($pallet, $base, $request->validated());

        if (isset($result['error'])) {
            return response()->json($result['error'], $result['status']);
        }

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
            return response()->json(['message' => 'El pallet está finalizado. Reabrilo antes de modificar sus bases.'], 409);
        }

        $data = $request->validated();

        $result = DB::transaction(function () use ($data, $pallet, $base) {
            // Las FKs de pallet_base_order_items y activity_logs toman
            // FOR KEY SHARE en pallet/bases al insertar. Se adquieren
            // FOR SHARE en orden D-020 ANTES del lock del order_item:
            // si detach u otro recorrido retiene pallet/bases FOR UPDATE,
            // aquí se espera sin poseer el item — nunca hay ciclo.
            Pallet::query()->whereKey($pallet->id)->sharedLock()->first();
            PalletBase::query()->whereKey($base->id)->sharedLock()->first();

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

            $currentAllocation = $allocations->firstWhere('base_id', $base->id);
            $oldQty = $currentAllocation ? (int) $currentAllocation->qty : 0;
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

        return DB::transaction(function () use ($pallet, $base) {
            // El activity log toma FOR KEY SHARE en el pallet: se
            // adquiere FOR SHARE antes del lock FOR UPDATE de la base
            // (orden D-020) para no invertir la espera.
            Pallet::query()->whereKey($pallet->id)->sharedLock()->first();

            $lockedBase = PalletBase::whereKey($base->id)->lockForUpdate()->first();
            if (! $lockedBase || $lockedBase->pallet_id !== $pallet->id) {
                return response()->json(['message' => 'Base no encontrada'], 404);
            }

            $baseName = $lockedBase->name ?? 'Sin nombre';
            $baseId = $lockedBase->id;

            // Las fotos caen por cascada FK; se registran intenciones de
            // borrado para todos sus objetos.
            $paths = $lockedBase->photos()->lockForUpdate()->pluck('path')->all();

            $lockedBase->delete();

            ActivityLogger::log(
                action: 'base_deleted',
                entityType: 'pallet_base',
                entityId: $baseId,
                description: "Base eliminada: '{$baseName}'",
                palletId: $pallet->id,
                oldValues: ['name' => $baseName, 'note' => $lockedBase->note],
            );

            $this->storageCleanup->scheduleDeletion($paths, 'base_deleted');

            return response()->json(['message' => 'Base eliminada'], 200);
        });
    }
}
