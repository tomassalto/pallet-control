<?php

namespace App\Services;

use App\Helpers\ActivityLogger;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Pallet;
use App\Models\PalletBase;
use App\Models\PalletBasePhoto;
use Illuminate\Support\Facades\DB;

/**
 * Autoridad única de finalización/reapertura: bloquea la entidad y sus
 * dependencias en orden D-020, re-evalúa los requisitos dentro de la
 * transacción y confirma estado + auditoría de forma atómica.
 */
class FinalizationService
{
    /**
     * Orden de locks: pallet FOR UPDATE → bases FOR SHARE → fotos FOR SHARE
     * → asignaciones FOR SHARE.
     *
     * @return array{pallet: Pallet}|array{error: array, status: int}
     */
    public function finalizePallet(Pallet $pallet): array
    {
        return DB::transaction(function () use ($pallet) {
            $locked = Pallet::query()->lockForUpdate()->find($pallet->id);
            if (! $locked) {
                return ['error' => ['message' => 'Pallet no encontrado'], 'status' => 404];
            }

            if ($locked->status === 'done') {
                return ['error' => ['message' => 'El pallet ya está finalizado.'], 'status' => 409];
            }

            $baseIds = PalletBase::query()
                ->where('pallet_id', $locked->id)
                ->orderBy('id')
                ->sharedLock()
                ->pluck('id');
            $basesCount = $baseIds->count();

            // PostgreSQL no admite FOR SHARE con agregados/DISTINCT/GROUP BY:
            // se traen los ids y se cuenta en PHP.
            $totalPhotos = 0;
            $basesWithProducts = 0;
            if ($basesCount > 0) {
                $photoIds = PalletBasePhoto::query()
                    ->whereIn('base_id', $baseIds)
                    ->orderBy('id')
                    ->sharedLock()
                    ->pluck('id');
                $totalPhotos = count($photoIds->all());

                $allocationRows = DB::table('pallet_base_order_items')
                    ->whereIn('base_id', $baseIds)
                    ->orderBy('order_item_id')
                    ->orderBy('base_id')
                    ->sharedLock()
                    ->get(['base_id']);
                $basesConProductos = [];
                foreach ($allocationRows as $row) {
                    $basesConProductos[$row->base_id] = true;
                }
                $basesWithProducts = count($basesConProductos);
            }

            $check = PalletService::evaluateFinalization($basesCount, $totalPhotos, $basesWithProducts);
            if (! $check['can']) {
                return [
                    'error' => ['message' => $check['reason'], ...$check['details']],
                    'status' => 409,
                ];
            }

            $oldStatus = $locked->status;
            $locked->update(['status' => 'done']);

            ActivityLogger::log(
                action: 'pallet_finalized',
                entityType: 'pallet',
                entityId: $locked->id,
                description: "Pallet '{$locked->code}' finalizado",
                palletId: $locked->id,
                oldValues: ['status' => $oldStatus],
                newValues: ['status' => 'done'],
            );

            return ['pallet' => $locked];
        });
    }

    /**
     * @return array{pallet: Pallet}|array{error: array, status: int}
     */
    public function reopenPallet(Pallet $pallet): array
    {
        return DB::transaction(function () use ($pallet) {
            $locked = Pallet::query()->lockForUpdate()->find($pallet->id);
            if (! $locked) {
                return ['error' => ['message' => 'Pallet no encontrado'], 'status' => 404];
            }

            if ($locked->status !== 'done') {
                return ['error' => ['message' => 'Solo se pueden reabrir pallets que estén finalizados'], 'status' => 409];
            }

            $oldStatus = $locked->status;
            $locked->update(['status' => 'open']);

            ActivityLogger::log(
                action: 'pallet_reopened',
                entityType: 'pallet',
                entityId: $locked->id,
                description: "Pallet '{$locked->code}' reabierto",
                palletId: $locked->id,
                oldValues: ['status' => $oldStatus],
                newValues: ['status' => 'open'],
            );

            return ['pallet' => $locked];
        });
    }

    /**
     * Orden de locks: order_items FOR SHARE → order FOR UPDATE →
     * asignaciones FOR SHARE.
     *
     * @return array{order: Order}|array{error: array, status: int}
     */
    public function finalizeOrder(Order $order): array
    {
        return DB::transaction(function () use ($order) {
            $lockedIds = OrderItem::query()
                ->where('order_id', $order->id)
                ->orderBy('id')
                ->sharedLock()
                ->pluck('id');

            $locked = Order::query()->lockForUpdate()->find($order->id);
            if (! $locked) {
                return ['error' => ['message' => 'Pedido no encontrado'], 'status' => 404];
            }

            if ($locked->status === 'done') {
                return ['error' => ['message' => 'El pedido ya está finalizado.'], 'status' => 409];
            }

            $items = OrderItem::query()
                ->where('order_id', $locked->id)
                ->orderBy('id')
                ->get(['id', 'qty']);

            // Si el conjunto de ítems cambió entre el FOR SHARE y el lock del
            // pedido (un alta concurrente sólo bloquea el pedido), evaluarlo
            // sería leer una fila sin lock — y bloquearla ahora invertiría
            // el orden D-020. Se rechaza y el cliente reintenta.
            if ($items->pluck('id')->all() !== $lockedIds->all()) {
                return [
                    'error' => ['message' => 'El pedido cambió mientras se finalizaba. Volvé a intentarlo.'],
                    'status' => 409,
                ];
            }

            // attach/detach bloquean el pedido, así que esta lectura es estable.
            $palletIds = DB::table('order_pallet')
                ->where('order_id', $locked->id)
                ->pluck('pallet_id');

            // Sin joins en la query con lock: unir traería locks extra sobre
            // pallet_bases/order_pallet fuera del orden D-020.
            $assignedByItem = [];
            if ($items->isNotEmpty()) {
                $allocations = DB::table('pallet_base_order_items')
                    ->whereIn('order_item_id', $lockedIds)
                    ->orderBy('order_item_id')
                    ->orderBy('base_id')
                    ->sharedLock()
                    ->get(['order_item_id', 'base_id', 'qty']);

                if ($allocations->isNotEmpty() && $palletIds->isNotEmpty()) {
                    $validBaseIds = PalletBase::query()
                        ->whereIn('id', $allocations->pluck('base_id')->unique())
                        ->whereIn('pallet_id', $palletIds)
                        ->pluck('id');

                    foreach ($allocations as $allocation) {
                        if ($validBaseIds->contains($allocation->base_id)) {
                            $itemId = (int) $allocation->order_item_id;
                            $assignedByItem[$itemId] = ($assignedByItem[$itemId] ?? 0) + (int) $allocation->qty;
                        }
                    }
                }
            }

            $check = OrderService::evaluateFinalization($items, $palletIds->isNotEmpty(), $assignedByItem);
            if (! $check['can']) {
                return ['error' => ['message' => $check['reason']], 'status' => 409];
            }

            $oldStatus = $locked->status;
            $locked->update(['status' => 'done']);

            ActivityLogger::log(
                action: 'order_finalized',
                entityType: 'order',
                entityId: $locked->id,
                description: "Pedido '{$locked->code}' finalizado",
                oldValues: ['status' => $oldStatus],
                newValues: ['status' => 'done'],
                orderId: $locked->id,
            );

            return ['order' => $locked];
        });
    }
}
