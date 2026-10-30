<?php

namespace App\Services;

use App\Helpers\ActivityLogger;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Pallet;
use Illuminate\Support\Facades\DB;

class OrderPalletAssociationService
{
    /**
     * @return array{order: Order, pallet: Pallet, order_created: bool}|array{error: array{message: string}, status: int}
     */
    public function attachByCode(Pallet $pallet, string $orderCode): array
    {
        return DB::transaction(function () use ($pallet, $orderCode) {
            $lockedPallet = Pallet::query()->lockForUpdate()->findOrFail($pallet->id);
            $order = Order::query()->firstOrCreate(
                ['code' => $orderCode],
                ['status' => 'open'],
            );
            $orderCreated = $order->wasRecentlyCreated;
            $lockedOrder = Order::query()->lockForUpdate()->findOrFail($order->id);

            return $this->attachLocked($lockedOrder, $lockedPallet, $orderCreated);
        });
    }

    /**
     * @return array{order: Order, pallet: Pallet, order_created: bool}|array{error: array{message: string}, status: int}
     */
    public function attachExisting(Order $order, Pallet $pallet): array
    {
        return DB::transaction(function () use ($order, $pallet) {
            $lockedPallet = Pallet::query()->lockForUpdate()->findOrFail($pallet->id);
            $lockedOrder = Order::query()->lockForUpdate()->findOrFail($order->id);

            return $this->attachLocked($lockedOrder, $lockedPallet, false);
        });
    }

    /**
     * Desvincula un pallet de un pedido eliminando sus asignaciones.
     * Orden de locks D-020: pallet → bases por id → order_items del
     * pedido por id → pedido; las asignaciones se borran al final.
     * La asociación se reevalúa con los locks tomados.
     *
     * @return array{ok: true}|array{error: array{message: string}, status: int}
     */
    public function detach(Order $order, Pallet $pallet): array
    {
        return DB::transaction(function () use ($order, $pallet) {
            $lockedPallet = Pallet::query()->lockForUpdate()->findOrFail($pallet->id);
            $baseIds = $lockedPallet->bases()->orderBy('id')->lockForUpdate()->pluck('id');
            $orderItemIds = OrderItem::where('order_id', $order->id)
                ->orderBy('id')
                ->lockForUpdate()
                ->pluck('id');
            $lockedOrder = Order::query()->lockForUpdate()->findOrFail($order->id);

            $attached = $lockedOrder->pallets()
                ->where('pallets.id', $lockedPallet->id)
                ->exists();

            if (! $attached) {
                return [
                    'error' => ['message' => 'El pallet no está asociado a este pedido'],
                    'status' => 404,
                ];
            }

            if ($baseIds->isNotEmpty() && $orderItemIds->isNotEmpty()) {
                DB::table('pallet_base_order_items')
                    ->whereIn('base_id', $baseIds)
                    ->whereIn('order_item_id', $orderItemIds)
                    ->delete();
            }

            $lockedOrder->pallets()->detach($lockedPallet->id);

            ActivityLogger::log(
                action: 'pallet_detached',
                entityType: 'order',
                entityId: $lockedOrder->id,
                description: "Pallet '{$lockedPallet->code}' desvinculado del pedido '{$lockedOrder->code}'. Se eliminaron las asignaciones de productos a las bases de este pallet.",
                palletId: $lockedPallet->id,
                oldValues: ['pallet_code' => $lockedPallet->code],
                orderId: $lockedOrder->id,
            );

            return ['ok' => true];
        });
    }

    /**
     * @return array{order: Order, pallet: Pallet, order_created: bool}|array{error: array{message: string}, status: int}
     */
    private function attachLocked(Order $order, Pallet $pallet, bool $orderCreated): array
    {
        $alreadyAttached = DB::table('order_pallet')
            ->where('order_id', $order->id)
            ->where('pallet_id', $pallet->id)
            ->exists();

        if ($alreadyAttached) {
            return [
                'error' => ['message' => 'El pedido ya está asociado a este pallet.'],
                'status' => 409,
            ];
        }

        if ($orderCreated) {
            ActivityLogger::log(
                action: 'order_created',
                entityType: 'order',
                entityId: $order->id,
                description: "Pedido '{$order->code}' creado",
                newValues: ['code' => $order->code, 'status' => 'open'],
                orderId: $order->id,
            );
        }

        DB::table('order_pallet')->insert([
            'order_id' => $order->id,
            'pallet_id' => $pallet->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        ActivityLogger::log(
            action: 'order_assigned',
            entityType: 'order',
            entityId: $order->id,
            description: "Pedido '{$order->code}' asociado al pallet '{$pallet->code}'",
            palletId: $pallet->id,
            newValues: ['order_code' => $order->code],
            orderId: $order->id,
        );

        return [
            'order' => $order,
            'pallet' => $pallet,
            'order_created' => $orderCreated,
        ];
    }
}
