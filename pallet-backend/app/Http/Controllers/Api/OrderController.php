<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreOrderRequest;
use App\Helpers\TelegramNotifier;
use App\Models\Customer;
use App\Models\Order;
use App\Models\Product;
use App\Services\OrderService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class OrderController extends Controller
{
    public function index(Request $request)
    {
        $q = Order::with([
                'customer',
                'pallets:id,code,status',
                'items' => fn ($q) => $q->select('id', 'order_id', 'description', 'qty', 'status', 'ean'),
            ])
            ->withCount(['items as total_items'])
            ->addSelect(DB::raw(
                '(SELECT COALESCE(SUM(qty),0) FROM order_items WHERE order_items.order_id = orders.id) as total_qty'
            ))
            ->addSelect(DB::raw(
                '(SELECT COALESCE(SUM(pboi.qty),0)
                  FROM pallet_base_order_items pboi
                  JOIN order_items oi ON oi.id = pboi.order_item_id
                  WHERE oi.order_id = orders.id) as assigned_qty'
            ))
            ->orderByDesc('id');

        if ($request->filled('status')) {
            $q->where('status', $request->string('status'));
        }

        if ($request->filled('customer_id')) {
            $q->where('customer_id', $request->integer('customer_id'));
        }

        // Búsqueda por código (para modales/autocomplete)
        if ($request->filled('search')) {
            $q->where('code', 'like', '%' . $request->string('search') . '%');
        }

        // Filtro por fecha de creación
        if ($request->filled('date_from')) {
            $q->whereDate('orders.created_at', '>=', $request->string('date_from'));
        }
        if ($request->filled('date_to')) {
            $q->whereDate('orders.created_at', '<=', $request->string('date_to'));
        }

        // Fetch data
        if ($request->filled('limit')) {
            $result = $q->limit($request->integer('limit'))->get();
        } else {
            $result = $q->paginate(20);
        }

        // Enriquecer items con image_url desde products (1 query extra para todos los EANs)
        $collection = $result instanceof \Illuminate\Pagination\LengthAwarePaginator
            ? $result->getCollection()
            : $result;

        $allEans = $collection
            ->flatMap(fn ($o) => $o->items->pluck('ean'))
            ->filter()->unique()->values()->all();

        if (!empty($allEans)) {
            $images = Product::whereIn('ean', $allEans)
                ->whereNotNull('image_url')
                ->pluck('image_url', 'ean');

            foreach ($collection as $order) {
                foreach ($order->items as $item) {
                    $item->setAttribute('image_url', $images[$item->ean] ?? null);
                }
            }
        }

        return $result;
    }

    public function store(StoreOrderRequest $request)
    {
        $data = $request->validated();

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
            action: 'order_created',
            entityType: 'order',
            entityId: $order->id,
            description: "Pedido '{$order->code}' creado",
            newValues: ['code' => $order->code, 'status' => 'open'],
            orderId: $order->id,
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

        // Lookup de imágenes (1 query extra)
        $eans = $order->items->pluck('ean')->filter()->unique()->values()->all();
        $productImages = Product::whereIn('ean', $eans)
            ->whereNotNull('image_url')
            ->pluck('image_url', 'ean');

        // Mapear usando relaciones ya cargadas (0 queries adicionales)
        $itemsWithLocations = $order->items->map(function ($item) use ($productImages) {
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
                'image_url'   => $productImages[$item->ean] ?? null,
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
            action: 'order_assigned',
            entityType: 'order',
            entityId: $order->id,
            description: "Pedido '{$order->code}' asociado al pallet '{$pallet->code}'",
            palletId: $pallet->id,
            newValues: ['order_code' => $order->code],
            orderId: $order->id,
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

        $orders = Order::with(['items', 'pallets.bases.orderItems'])
            ->whereIn('id', $data['order_ids'])
            ->get()
            ->keyBy('id');

        $result = [];
        foreach ($data['order_ids'] as $id) {
            $order = $orders->get($id);
            $result[$id] = $order ? OrderService::canFinalize($order)['can'] : false;
        }

        return response()->json($result);
    }

    // GET /orders/{order}/can-finalize
    public function canFinalize(Order $order)
    {
        $order->load(['items', 'pallets.bases.orderItems']);

        ['can' => $can, 'reason' => $reason] = OrderService::canFinalize($order);

        return response()->json(['can_finalize' => $can, 'reason' => $reason]);
    }

    // POST /orders/{order}/finalize
    public function finalize(Order $order)
    {
        $order->load(['items', 'pallets.bases.orderItems']);

        ['can' => $can, 'reason' => $reason] = OrderService::canFinalize($order);

        if (! $can) {
            return response()->json(['message' => $reason], 422);
        }

        $order->update(['status' => 'done']);

        \App\Helpers\ActivityLogger::log(
            action: 'order_finalized',
            entityType: 'order',
            entityId: $order->id,
            description: "Pedido '{$order->code}' finalizado",
            oldValues: ['status' => 'open'],
            newValues: ['status' => 'done'],
            orderId: $order->id,
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
            action: 'pallet_detached',
            entityType: 'order',
            entityId: $order->id,
            description: "Pallet '{$pallet->code}' desvinculado del pedido '{$order->code}'. Se eliminaron las asignaciones de productos a las bases de este pallet.",
            palletId: $pallet->id,
            oldValues: ['pallet_code' => $pallet->code],
            orderId: $order->id,
        );

        TelegramNotifier::send("⛓️ Pallet `{$pallet->code}` desvinculado del pedido `#{$order->code}`");

        return response()->json([
            'message' => 'Pallet desvinculado correctamente. Se eliminaron las asignaciones de productos a las bases de este pallet.',
            'pallets' => $order->pallets()->get(),
        ]);
    }
}
