<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Helpers\ActivityLogger;
use App\Helpers\TelegramNotifier;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Pallet;
use App\Models\Product;
use App\Services\PalletService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class PalletController extends Controller
{
    public function index(Request $request)
    {
        $q = Pallet::select('id', 'code', 'status', 'note', 'created_at')
            ->with([
                'orders' => fn ($q) => $q->select('orders.id', 'orders.code', 'orders.status', 'orders.customer_id')
                    ->with('customer:id,name'),
                'photos',
            ])
            ->withCount('orders as orders_count')
            ->orderByDesc('id');

        if ($request->filled('status')) {
            $q->where('status', $request->string('status'));
        }

        return $q->paginate(20);
    }

    // crea pallet "vacío" (sin pedido). Opcional nota.
    public function store(Request $request)
    {
        $data = $request->validate([
            'note' => ['nullable', 'string'],
        ]);

        $today = now()->format('Ymd');
        $countToday = Pallet::whereDate('created_at', now()->toDateString())->count() + 1;
        $code = sprintf("PAL-%s-%04d", $today, $countToday);

        $pallet = Pallet::create([
            'code' => $code,
            'status' => 'open',
            'note' => $data['note'] ?? null,
            'created_by' => null,
        ]);

        ActivityLogger::log(
            action: 'pallet_created',
            entityType: 'pallet',
            entityId: $pallet->id,
            description: "Pallet '{$pallet->code}' creado",
            palletId: $pallet->id,
            newValues: ['code' => $pallet->code, 'status' => 'open'],
        );

        TelegramNotifier::send("📦 *Nuevo pallet* `{$pallet->code}` creado");

        return response()->json($pallet, 201);
    }

    // GET /pallets/{pallet}  -> pallet + orders + photos + bases + content(real)
    public function show(Request $request, Pallet $pallet)
    {
        $pallet->load(['orders.items', 'photos', 'bases.photos', 'bases.orderItems']);

        // Lookup de imágenes de productos (1 query extra, cubre orders.items + bases.orderItems)
        $allEans = collect();
        foreach ($pallet->orders as $order) {
            $allEans = $allEans->merge($order->items->pluck('ean'));
        }
        foreach ($pallet->bases as $base) {
            $allEans = $allEans->merge($base->orderItems->pluck('ean'));
        }
        $allEans = $allEans->filter()->unique()->values()->all();

        if (!empty($allEans)) {
            $productImages = Product::whereIn('ean', $allEans)
                ->whereNotNull('image_url')
                ->pluck('image_url', 'ean');

            foreach ($pallet->orders as $order) {
                foreach ($order->items as $item) {
                    $item->setAttribute('image_url', $productImages[$item->ean] ?? null);
                }
            }
            foreach ($pallet->bases as $base) {
                foreach ($base->orderItems as $item) {
                    $item->setAttribute('image_url', $productImages[$item->ean] ?? null);
                }
            }
        }

        // Los logs están en el endpoint dedicado /activity-logs, no se duplican aquí

        return response()->json([
            'pallet' => $pallet,
            'orders' => $pallet->orders,
            'photos' => $pallet->photos,   // url auto-incluida por el modelo
            'bases'  => $pallet->bases,    // photos.url auto-incluida por el modelo
            'activity_logs' => [],
        ]);
    }

    public function updateStatus(Request $request, Pallet $pallet)
    {
        $data = $request->validate([
            'status' => 'required|in:open,paused,done',
            'note' => 'nullable|string',
        ]);

        $pallet->update($data);

        return response()->json($pallet->load('orders'));
    }

    // GET /pallets/{pallet}/activity-logs
    public function activityLogs(Pallet $pallet)
    {
        $logs = \App\Models\ActivityLog::where('pallet_id', $pallet->id)
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
            'pallet' => $pallet,
            'logs' => $logs,
        ]);
    }

    // ✅ Asignar pedido al pallet por order_code (numérico)
    // POST /pallets/{pallet}/attach-order  { order_code: "12345" }
    public function attachOrder(Request $request, Pallet $pallet)
    {
        $data = $request->validate([
            'order_code' => ['required', 'regex:/^\d+$/'],
        ]);

        $wasNewOrder = !Order::where('code', $data['order_code'])->exists();
        $order = Order::firstOrCreate(
            ['code' => $data['order_code']],
            ['status' => 'open']
        );

        // Si el pedido es nuevo, registrar su creación
        if ($wasNewOrder) {
            ActivityLogger::log(
                action: 'order_created',
                entityType: 'order',
                entityId: $order->id,
                description: "Pedido '{$order->code}' creado",
                newValues: ['code' => $order->code, 'status' => 'open'],
                orderId: $order->id,
            );
        }

        $pallet->orders()->syncWithoutDetaching([$order->id]);

        ActivityLogger::log(
            action: 'order_assigned',
            entityType: 'order',
            entityId: $order->id,
            description: "Pedido '{$order->code}' asignado al pallet '{$pallet->code}'",
            palletId: $pallet->id,
            newValues: ['order_code' => $order->code],
            orderId: $order->id,
        );

        if ($wasNewOrder) {
            TelegramNotifier::send("🆕 *Nuevo pedido* `#{$order->code}` creado y asignado al pallet `{$pallet->code}`");
        } else {
            TelegramNotifier::send("🔗 Pedido `#{$order->code}` asignado al pallet `{$pallet->code}`");
        }

        return response()->json([
            'message' => 'Pedido asignado al pallet.',
            'order' => $order,
            'orders' => $pallet->orders()->get(),
        ], 201);
    }

    public function lastOpen()
    {
        $pallet = Pallet::where('status', 'open')
            ->orderByDesc('id')
            ->first();

        if (!$pallet) {
            return response()->json(['pallet' => null]);
        }

        // si querés incluir pedidos asociados:
        $pallet->load('orders');

        return response()->json(['pallet' => $pallet]);
    }

    // Verificar si el pallet puede ser finalizado
    public function canFinalize(Pallet $pallet)
    {
        $pallet->load(['bases.photos', 'bases.orderItems']);

        $check = PalletService::canFinalize($pallet);

        return response()->json([
            'can_finalize' => $check['can'],
            ...$check['details'],
        ]);
    }

    // Finalizar pallet (cambiar status a 'done')
    public function finalize(Pallet $pallet)
    {
        $pallet->load(['bases.photos', 'bases.orderItems']);

        $check = PalletService::canFinalize($pallet);

        if (! $check['can']) {
            return response()->json([
                'message' => $check['reason'],
                ...$check['details'],
            ], 400);
        }

        $oldStatus = $pallet->status;
        $pallet->update(['status' => 'done']);

        ActivityLogger::log(
            action: 'pallet_finalized',
            entityType: 'pallet',
            entityId: $pallet->id,
            description: "Pallet '{$pallet->code}' finalizado",
            palletId: $pallet->id,
            oldValues: ['status' => $oldStatus],
            newValues: ['status' => 'done'],
        );

        TelegramNotifier::send("🎉 Pallet `{$pallet->code}` *cerrado* ✓");

        return response()->json([
            'message' => 'Pallet finalizado correctamente',
            'pallet' => $pallet->load(['orders', 'bases.photos']),
        ]);
    }

    // Reabrir pallet (cambiar status de 'done' a 'open')
    public function reopen(Pallet $pallet)
    {
        if ($pallet->status !== 'done') {
            return response()->json([
                'message' => 'Solo se pueden reabrir pallets que estén finalizados',
            ], 400);
        }

        $oldStatus = $pallet->status;
        $pallet->update(['status' => 'open']);

        ActivityLogger::log(
            action: 'pallet_reopened',
            entityType: 'pallet',
            entityId: $pallet->id,
            description: "Pallet '{$pallet->code}' reabierto",
            palletId: $pallet->id,
            oldValues: ['status' => $oldStatus],
            newValues: ['status' => 'open'],
        );

        TelegramNotifier::send("🔄 Pallet `{$pallet->code}` *reabierto*");

        return response()->json([
            'message' => 'Pallet reabierto correctamente',
            'pallet' => $pallet->load(['orders', 'bases.photos']),
        ]);
    }

    // Eliminar pallet
    public function destroy(Pallet $pallet)
    {
        $palletCode = $pallet->code;
        $palletId = $pallet->id;

        // Eager-load photos y bases.photos en 2 queries fijas (evita N+1)
        $pallet->load('photos', 'bases.photos');

        // Eliminar fotos del storage
        foreach ($pallet->photos as $photo) {
            Storage::disk(config('filesystems.default', 'public'))->delete($photo->path);
        }

        // Eliminar fotos de las bases
        foreach ($pallet->bases as $base) {
            foreach ($base->photos as $photo) {
                Storage::disk(config('filesystems.default', 'public'))->delete($photo->path);
            }
        }

        $pallet->delete();

        ActivityLogger::log(
            action: 'pallet_deleted',
            entityType: 'pallet',
            entityId: $palletId,
            description: "Pallet '{$palletCode}' eliminado",
            oldValues: ['code' => $palletCode],
        );

        TelegramNotifier::send("🗑️ Pallet `{$palletCode}` eliminado");

        return response()->json([
            'message' => 'Pallet eliminado correctamente',
        ], 200);
    }
}
