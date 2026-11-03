<?php

namespace App\Http\Controllers\Api\Admin;

use App\Helpers\ActivityLogger;
use App\Models\ActivityLog;
use App\Models\Order;
use App\Models\OrderTicketPhoto;
use App\Models\Pallet;
use App\Models\PalletBasePhoto;
use App\Models\PalletPhoto;
use App\Services\StorageCleanupService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\DB;

class StorageController extends Controller
{
    public function __construct(
        private readonly StorageCleanupService $storageCleanup,
    ) {}

    // ── stats() ───────────────────────────────────────────────────────────

    public function stats(): JsonResponse
    {
        $palletPhotosCount = PalletPhoto::count();
        $basePhotosCount = PalletBasePhoto::count();
        $ticketPhotosCount = OrderTicketPhoto::count();

        return response()->json([
            'pallet_photos_count' => $palletPhotosCount,
            'base_photos_count' => $basePhotosCount,
            'ticket_photos_count' => $ticketPhotosCount,
            'activity_logs_count' => ActivityLog::count(),
            'ocr_logs_count' => OrderTicketPhoto::whereNotNull('ocr_log')->count(),
            'total_photos' => $palletPhotosCount + $basePhotosCount + $ticketPhotosCount,
        ]);
    }

    // ── pallets() ─────────────────────────────────────────────────────────

    public function pallets(Request $request): JsonResponse
    {
        $query = Pallet::withCount(['orders', 'photos', 'bases'])
            ->orderByDesc('created_at');

        if ($request->query('empty') == '1') {
            $query->having('orders_count', '=', 0);
        }

        $paginated = $query->paginate(20);

        $paginated->getCollection()->transform(function (Pallet $pallet) {
            return [
                'id' => $pallet->id,
                'code' => $pallet->code,
                'status' => $pallet->status,
                'created_at' => $pallet->created_at,
                'orders_count' => $pallet->orders_count,
                'photos_count' => $pallet->photos_count,
                'bases_count' => $pallet->bases_count,
            ];
        });

        return response()->json($paginated);
    }

    // ── orders() ─────────────────────────────────────────────────────────

    public function orders(Request $request): JsonResponse
    {
        $query = Order::withCount(['items', 'tickets'])
            ->orderByDesc('created_at');

        if ($request->query('empty') == '1') {
            $query->having('items_count', '=', 0);
        }

        $paginated = $query->paginate(20);

        $paginated->getCollection()->transform(function (Order $order) {
            return [
                'id' => $order->id,
                'code' => $order->code,
                'status' => $order->status,
                'created_at' => $order->created_at,
                'items_count' => $order->items_count,
                'tickets_count' => $order->tickets_count,
            ];
        });

        return response()->json($paginated);
    }

    // ── deletePallet() ────────────────────────────────────────────────────

    public function deletePallet(Request $request, int $id): JsonResponse
    {
        return DB::transaction(function () use ($id) {
            // Un pallet finalizado es inmutable: el borrado exige reapertura.
            $pallet = Pallet::query()->whereKey($id)->lockForUpdate()->first();
            if (! $pallet) {
                return response()->json(['message' => 'Pallet no encontrado'], 404);
            }
            if ($pallet->status === 'done') {
                return response()->json([
                    'message' => 'El pallet está finalizado. Reabrilo antes de eliminarlo.',
                ], 409);
            }

            // Las fotos se leen bajo lock y cada objeto queda con una
            // intención durable; el filesystem corre post-commit vía
            // StorageCleanupService — nada de I/O externo con la tx abierta.
            $pallet->load([
                'photos' => fn ($q) => $q->lockForUpdate(),
                'bases.photos' => fn ($q) => $q->lockForUpdate(),
            ]);

            $paths = $pallet->photos->pluck('path')
                ->merge($pallet->bases->flatMap(fn ($base) => $base->photos->pluck('path')))
                ->all();

            $code = $pallet->code;

            // Las fotos del pallet y de sus bases caen por cascada con la fila.
            $pallet->delete();

            ActivityLogger::log(
                action: 'admin_pallet_deleted',
                entityType: 'pallet',
                entityId: $id,
                description: "Pallet '{$code}' eliminado desde administración de storage",
                oldValues: ['code' => $code],
            );

            $this->storageCleanup->scheduleDeletion($paths, 'admin_pallet_deleted');

            return response()->json([
                'deleted' => true,
                'code' => $code,
            ]);
        });
    }

    // ── deleteOrder() ─────────────────────────────────────────────────────

    public function deleteOrder(Request $request, int $id): JsonResponse
    {
        return DB::transaction(function () use ($id) {
            // Un pedido finalizado es inmutable: el borrado exige reapertura.
            $order = Order::query()->whereKey($id)->lockForUpdate()->first();
            if (! $order) {
                return response()->json(['message' => 'Pedido no encontrado'], 404);
            }
            if ($order->status === 'done') {
                return response()->json([
                    'message' => 'El pedido está finalizado. Reabrilo antes de eliminarlo.',
                ], 409);
            }

            // Idéntico patrón: intenciones durables dentro de la tx,
            // borrado físico post-commit por StorageCleanupService.
            $order->load(['tickets.photos' => fn ($q) => $q->lockForUpdate()]);

            $paths = $order->tickets
                ->flatMap(fn ($ticket) => $ticket->photos->pluck('path'))
                ->all();

            $code = $order->code;

            // Sin orderId: la fila se borra a continuación y la FK del
            // log no puede quedar apuntando al pedido eliminado.
            ActivityLogger::log(
                action: 'admin_order_deleted',
                entityType: 'order',
                entityId: $id,
                description: "Pedido '{$code}' eliminado desde administración de storage",
                oldValues: ['code' => $code],
            );

            // Las fotos de los tickets caen por cascada con la fila.
            $order->delete();

            $this->storageCleanup->scheduleDeletion($paths, 'admin_order_deleted');

            return response()->json([
                'deleted' => true,
                'code' => $code,
            ]);
        });
    }
}
