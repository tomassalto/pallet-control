<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Helpers\ActivityLogger;
use App\Helpers\TelegramNotifier;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Pallet;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class PalletController extends Controller
{
    public function index(Request $request)
    {
        $q = Pallet::select('id', 'code', 'status', 'note', 'created_at')
            ->with('orders:id,code,status')
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
            'pallet_created',
            'pallet',
            $pallet->id,
            "Pallet '{$pallet->code}' creado",
            $pallet->id,
            null,
            ['code' => $pallet->code, 'status' => 'open']
        );

        TelegramNotifier::send("📦 *Nuevo pallet* `{$pallet->code}` creado");

        return response()->json($pallet, 201);
    }

    // GET /pallets/{pallet}  -> pallet + orders + photos + bases + content(real)
    public function show(Request $request, Pallet $pallet)
    {
        $pallet->load(['orders.items', 'photos', 'bases.photos', 'bases.orderItems']);

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
        // Obtener logs de actividad del pallet - solo cambios en bases
        $logs = \App\Models\ActivityLog::where('pallet_id', $pallet->id)
            ->where('entity_type', 'pallet_base')
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
                'order_created',
                'order',
                $order->id,
                "Pedido '{$order->code}' creado",
                null,
                null,
                ['code' => $order->code, 'status' => 'open'],
                $order->id
            );
        }

        $pallet->orders()->syncWithoutDetaching([$order->id]);

        ActivityLogger::log(
            'order_assigned',
            'order',
            $order->id,
            "Pedido '{$order->code}' asignado al pallet '{$pallet->code}'",
            $pallet->id,
            null,
            ['order_code' => $order->code],
            $order->id
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
        
        $basesCount = $pallet->bases->count();
        $totalPhotos = $pallet->bases->sum(function ($base) {
            return $base->photos->count();
        });

        // Verificar que todas las bases tengan al menos 1 producto
        $allBasesHaveProducts = $pallet->bases->every(function ($base) {
            return $base->orderItems->count() >= 1;
        });
        $basesWithProducts = $pallet->bases->filter(function ($base) {
            return $base->orderItems->count() >= 1;
        })->count();

        $canFinalize = $basesCount >= 2 && $totalPhotos >= 2 && $allBasesHaveProducts;

        return response()->json([
            'can_finalize' => $canFinalize,
            'bases_count' => $basesCount,
            'total_photos' => $totalPhotos,
            'bases_with_products' => $basesWithProducts,
            'all_bases_have_products' => $allBasesHaveProducts,
            'requirements' => [
                'bases' => ['required' => 2, 'current' => $basesCount],
                'photos' => ['required' => 2, 'current' => $totalPhotos],
                'bases_with_products' => ['required' => $basesCount, 'current' => $basesWithProducts],
            ],
        ]);
    }

    // Finalizar pallet (cambiar status a 'done')
    public function finalize(Pallet $pallet)
    {
        $pallet->load(['bases.photos', 'bases.orderItems']);
        
        $basesCount = $pallet->bases->count();
        $totalPhotos = $pallet->bases->sum(function ($base) {
            return $base->photos->count();
        });

        // Verificar que todas las bases tengan al menos 1 producto
        $allBasesHaveProducts = $pallet->bases->every(function ($base) {
            return $base->orderItems->count() >= 1;
        });

        if ($basesCount < 2 || $totalPhotos < 2 || !$allBasesHaveProducts) {
            return response()->json([
                'message' => 'El pallet no cumple con los requisitos para finalizar. Se requieren al menos 2 bases, 2 fotos en total, y todas las bases deben tener al menos 1 producto asignado.',
                'bases_count' => $basesCount,
                'total_photos' => $totalPhotos,
                'all_bases_have_products' => $allBasesHaveProducts,
            ], 400);
        }

        $oldStatus = $pallet->status;
        $pallet->update(['status' => 'done']);

        ActivityLogger::log(
            'pallet_finalized',
            'pallet',
            $pallet->id,
            "Pallet '{$pallet->code}' finalizado",
            $pallet->id,
            ['status' => $oldStatus],
            ['status' => 'done']
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
            'pallet_reopened',
            'pallet',
            $pallet->id,
            "Pallet '{$pallet->code}' reabierto",
            $pallet->id,
            ['status' => $oldStatus],
            ['status' => 'open']
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
            Storage::disk('public')->delete($photo->path);
        }

        // Eliminar fotos de las bases
        foreach ($pallet->bases as $base) {
            foreach ($base->photos as $photo) {
                Storage::disk('public')->delete($photo->path);
            }
        }

        $pallet->delete();

        ActivityLogger::log(
            'pallet_deleted',
            'pallet',
            $palletId,
            "Pallet '{$palletCode}' eliminado",
            null,
            ['code' => $palletCode],
            null
        );

        TelegramNotifier::send("🗑️ Pallet `{$palletCode}` eliminado");

        return response()->json([
            'message' => 'Pallet eliminado correctamente',
        ], 200);
    }
}
