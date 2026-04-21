<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Helpers\TelegramNotifier;
use App\Models\Customer;
use App\Models\Order;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class OrderController extends Controller
{
    public function index(Request $request)
    {
        $q = Order::with('customer')->orderByDesc('id');

        if ($request->filled('status')) {
            $q->where('status', $request->string('status'));
        }

        if ($request->filled('customer_id')) {
            $q->where('customer_id', $request->integer('customer_id'));
        }

        return $q->paginate(20);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'customer_name' => 'nullable|string|max:255',
            'customer_id' => 'nullable|integer|exists:customers,id',
            'code' => 'required|string|max:255',
            'note' => 'nullable|string',
        ]);

        $customerId = $data['customer_id'] ?? null;

        // Atajo: si pasás customer_name, lo crea/usa
        if (!$customerId && !empty($data['customer_name'])) {
            $customer = Customer::firstOrCreate(['name' => $data['customer_name']]);
            $customerId = $customer->id;
        }

        $order = Order::create([
            'customer_id' => $customerId,
            'code' => $data['code'],
            'status' => 'open',
            'note' => $data['note'] ?? null,
        ]);

        \App\Helpers\ActivityLogger::log(
            'order_created',
            'order',
            $order->id,
            "Pedido '{$order->code}' creado",
            null,
            null,
            ['code' => $order->code, 'status' => 'open'],
            $order->id
        );

        TelegramNotifier::send("🆕 *Nuevo pedido* `#{$order->code}` creado");

        return response()->json($order->load('customer'), 201);
    }

    public function show(Order $order)
    {
        // Eager-load todo en 4 queries fijas (sin N+1 por items)
        $order->load([
            'pallets:id,code,status,created_at',
            'items'                => fn($q) => $q->orderBy('description'),
            'items.bases'          => fn($q) => $q->select('pallet_bases.id', 'pallet_bases.name', 'pallet_bases.pallet_id'),
            'items.bases.pallet:id,code',
            'tickets.photos',
        ]);

        // Mapear usando relaciones ya cargadas (0 queries adicionales)
        $itemsWithLocations = $order->items->map(function ($item) {
            $bases = $item->bases; // ya cargado

            $locations = $bases->map(fn($base) => [
                'pallet_id'   => $base->pallet->id,
                'pallet_code' => $base->pallet->code,
                'base_id'     => $base->id,
                'base_name'   => $base->name,
                'qty'         => $base->pivot->qty,
            ]);

            $calculatedDoneQty = $bases->sum(fn($base) => $base->pivot->qty ?? 0);

            if ($item->status === 'done' && $calculatedDoneQty === 0 && $bases->isEmpty()) {
                $calculatedDoneQty = $item->qty;
            }

            return [
                'id'          => $item->id,
                'ean'         => $item->ean,
                'ean_last4'   => $item->ean_last4,
                'description' => $item->description,
                'qty'         => $item->qty,
                'status'      => $item->status,
                'done_qty'    => $calculatedDoneQty,
                'locations'   => $locations,
            ];
        });

        return response()->json([
            'order'   => $order,
            'pallets' => $order->pallets,
            'items'   => $itemsWithLocations,
        ]);
    }

    public function updateStatus(Request $request, Order $order)
    {
        $data = $request->validate([
            'status' => 'required|in:open,paused,done',
            'note' => 'nullable|string',
        ]);

        $order->update($data);

        return response()->json($order->load('customer'));
    }

    public function lastOpen()
    {
        // Obtener el último pedido abierto (status = 'open') ordenado por updated_at descendente
        $order = Order::where('status', 'open')
            ->orderByDesc('updated_at')
            ->with('pallets:id,code,status')
            ->first();

        if (!$order) {
            return response()->json(['order' => null]);
        }

        return response()->json([
            'order' => $order,
        ]);
    }

    // POST /orders/{order}/attach-pallet { pallet_id: 123 }
    public function attachPallet(Request $request, Order $order)
    {
        $data = $request->validate([
            'pallet_id' => ['required', 'integer', 'exists:pallets,id'],
        ]);

        $pallet = \App\Models\Pallet::findOrFail($data['pallet_id']);

        // Asociar el pedido al pallet
        $pallet->orders()->syncWithoutDetaching([$order->id]);

        \App\Helpers\ActivityLogger::log(
            'order_assigned',
            'order',
            $order->id,
            "Pedido '{$order->code}' asociado al pallet '{$pallet->code}'",
            $pallet->id,
            null,
            ['order_code' => $order->code],
            $order->id
        );

        TelegramNotifier::send("🔗 Pedido `#{$order->code}` asociado al pallet `{$pallet->code}`");

        return response()->json([
            'message' => 'Pedido asociado al pallet.',
            'pallet' => $pallet,
            'pallets' => $order->pallets()->get(),
        ], 201);
    }

    // GET /orders/{order}/activity-logs
    public function activityLogs(Order $order)
    {
        $logs = \App\Models\ActivityLog::where('order_id', $order->id)
            ->with('user:id,name')
            ->orderByDesc('created_at')
            ->limit(200)
            ->get()
            ->map(function ($log) {
                return [
                    'id' => $log->id,
                    'action' => $log->action,
                    'description' => $log->description,
                    'user_name' => $log->user?->name ?? 'Sistema',
                    'created_at' => $log->created_at->format('Y-m-d H:i:s'),
                    'created_at_formatted' => $log->created_at->format('d/m/Y H:i:s'),
                ];
            });

        return response()->json([
            'order' => $order,
            'logs' => $logs,
        ]);
    }

    // POST /orders/can-finalize-batch  { order_ids: [1, 2, 3] }
    public function canFinalizeBatch(Request $request)
    {
        $data = $request->validate([
            'order_ids'   => ['required', 'array', 'max:100'],
            'order_ids.*' => ['integer'],
        ]);

        $orders = Order::with('items', 'pallets')
            ->whereIn('id', $data['order_ids'])
            ->get()
            ->keyBy('id');

        $result = [];
        foreach ($data['order_ids'] as $id) {
            $order = $orders->get($id);
            if (!$order) {
                $result[$id] = false;
                continue;
            }
            $result[$id] = (
                $order->items->isNotEmpty() &&
                $order->pallets->isNotEmpty() &&
                $order->items->where('status', 'pending')->isEmpty() &&
                $order->items->where('status', 'done')->isNotEmpty()
            );
        }

        return response()->json($result);
    }

    // GET /orders/{order}/can-finalize
    public function canFinalize(Order $order)
    {
        $order->load('items', 'pallets');

        // Verificar si hay items
        if ($order->items->isEmpty()) {
            return response()->json([
                'can_finalize' => false,
                'reason' => 'El pedido no tiene items',
            ]);
        }

        // Debe tener al menos 1 pallet asociado
        if ($order->pallets->isEmpty()) {
            return response()->json([
                'can_finalize' => false,
                'reason' => 'El pedido debe tener al menos 1 pallet asociado para finalizar',
            ]);
        }

        // Debe tener 1 o más productos asociados al pedido
        if ($order->items->count() < 1) {
            return response()->json([
                'can_finalize' => false,
                'reason' => 'El pedido debe tener al menos 1 producto asociado para finalizar',
            ]);
        }

        // Contar productos por estado
        $pendingItems = $order->items->where('status', 'pending');
        $completedItems = $order->items->where('status', 'done');

        // Debe haber 0 productos pendientes
        if ($pendingItems->count() > 0) {
            return response()->json([
                'can_finalize' => false,
                'reason' => 'Debe haber 0 productos pendientes para finalizar el pedido',
            ]);
        }

        // Debe haber 1 o más productos marcados como listo
        if ($completedItems->count() < 1) {
            return response()->json([
                'can_finalize' => false,
                'reason' => 'Debe haber al menos 1 producto marcado como listo para finalizar el pedido',
            ]);
        }

        return response()->json([
            'can_finalize' => true,
        ]);
    }

    // POST /orders/{order}/finalize
    public function finalize(Order $order)
    {
        $order->load('items', 'pallets');

        // Verificar si hay items
        if ($order->items->isEmpty()) {
            return response()->json([
                'message' => 'El pedido no tiene items',
            ], 422);
        }

        // Debe tener al menos 1 pallet asociado
        if ($order->pallets->isEmpty()) {
            return response()->json([
                'message' => 'El pedido debe tener al menos 1 pallet asociado para finalizar',
            ], 422);
        }

        // Debe tener 1 o más productos asociados al pedido
        if ($order->items->count() < 1) {
            return response()->json([
                'message' => 'El pedido debe tener al menos 1 producto asociado para finalizar',
            ], 422);
        }

        // Contar productos por estado
        $pendingItems = $order->items->where('status', 'pending');
        $completedItems = $order->items->where('status', 'done');

        // Debe haber 0 productos pendientes
        if ($pendingItems->count() > 0) {
            return response()->json([
                'message' => 'Debe haber 0 productos pendientes para finalizar el pedido',
            ], 422);
        }

        // Debe haber 1 o más productos marcados como listo
        if ($completedItems->count() < 1) {
            return response()->json([
                'message' => 'Debe haber al menos 1 producto marcado como listo para finalizar el pedido',
            ], 422);
        }

        $order->update(['status' => 'done']);

        \App\Helpers\ActivityLogger::log(
            'order_finalized',
            'order',
            $order->id,
            "Pedido '{$order->code}' finalizado",
            null,
            ['status' => 'open'],
            ['status' => 'done'],
            $order->id
        );

        TelegramNotifier::send("✅ Pedido `#{$order->code}` *finalizado*");

        return response()->json([
            'message' => 'Pedido finalizado correctamente',
            'order' => $order->load('customer'),
        ]);
    }

    // DELETE /orders/{order}/detach-pallet/{pallet}
    public function detachPallet(Order $order, \App\Models\Pallet $pallet)
    {
        // Verificar que el pallet esté asociado al pedido
        if (!$order->pallets()->where('pallets.id', $pallet->id)->exists()) {
            return response()->json([
                'message' => 'El pallet no está asociado a este pedido',
            ], 404);
        }

        // Obtener todas las bases del pallet
        $baseIds = $pallet->bases()->pluck('id');

        // Obtener todos los items del pedido
        $orderItemIds = $order->items()->pluck('id');

        // Eliminar relaciones entre items del pedido y bases del pallet
        if ($baseIds->isNotEmpty() && $orderItemIds->isNotEmpty()) {
            DB::table('pallet_base_order_items')
                ->whereIn('base_id', $baseIds)
                ->whereIn('order_item_id', $orderItemIds)
                ->delete();
        }

        // Desvincular el pallet
        $order->pallets()->detach($pallet->id);

        \App\Helpers\ActivityLogger::log(
            'pallet_detached',
            'order',
            $order->id,
            "Pallet '{$pallet->code}' desvinculado del pedido '{$order->code}'. Se eliminaron las asignaciones de productos a las bases de este pallet.",
            $pallet->id,
            ['pallet_code' => $pallet->code],
            null,
            $order->id
        );

        TelegramNotifier::send("⛓️ Pallet `{$pallet->code}` desvinculado del pedido `#{$order->code}`");

        return response()->json([
            'message' => 'Pallet desvinculado correctamente. Se eliminaron las asignaciones de productos a las bases de este pallet.',
            'pallets' => $order->pallets()->get(),
        ]);
    }
}
