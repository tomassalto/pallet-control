<?php

namespace App\Http\Controllers\Api\Admin;

use App\Models\Order;
use App\Models\OrderTicket;
use App\Models\OrderTicketPhoto;
use App\Models\Pallet;
use App\Models\PalletBase;
use App\Models\PalletBasePhoto;
use App\Models\PalletPhoto;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Storage;

class StorageController extends Controller
{
    // ── Helpers ───────────────────────────────────────────────────────────

    private function storageDisk(): \Illuminate\Contracts\Filesystem\Filesystem
    {
        return Storage::disk(config('filesystems.default', 'public'));
    }

    private function deleteFile(?string $path): void
    {
        if (empty($path)) {
            return;
        }

        try {
            $this->storageDisk()->delete($path);
        } catch (\Throwable) {
            // Skip files that cannot be deleted (already missing, permissions, etc.)
        }
    }

    // ── stats() ───────────────────────────────────────────────────────────

    public function stats(): JsonResponse
    {
        $palletPhotosCount = PalletPhoto::count();
        $basePhotosCount   = PalletBasePhoto::count();
        $ticketPhotosCount = OrderTicketPhoto::count();

        return response()->json([
            'pallet_photos_count' => $palletPhotosCount,
            'base_photos_count'   => $basePhotosCount,
            'ticket_photos_count' => $ticketPhotosCount,
            'activity_logs_count' => \App\Models\ActivityLog::count(),
            'ocr_logs_count'      => OrderTicketPhoto::whereNotNull('ocr_log')->count(),
            'total_photos'        => $palletPhotosCount + $basePhotosCount + $ticketPhotosCount,
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
                'id'           => $pallet->id,
                'code'         => $pallet->code,
                'status'       => $pallet->status,
                'created_at'   => $pallet->created_at,
                'orders_count' => $pallet->orders_count,
                'photos_count' => $pallet->photos_count,
                'bases_count'  => $pallet->bases_count,
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
                'id'            => $order->id,
                'code'          => $order->code,
                'status'        => $order->status,
                'created_at'    => $order->created_at,
                'items_count'   => $order->items_count,
                'tickets_count' => $order->tickets_count,
            ];
        });

        return response()->json($paginated);
    }

    // ── deletePallet() ────────────────────────────────────────────────────

    public function deletePallet(Request $request, int $id): JsonResponse
    {
        $pallet = Pallet::with(['bases.photos', 'photos'])->findOrFail($id);

        // Delete storage files for all PalletBasePhoto records
        foreach ($pallet->bases as $base) {
            foreach ($base->photos as $photo) {
                $this->deleteFile($photo->path);
            }
        }

        // Delete storage files for all PalletPhoto records
        foreach ($pallet->photos as $photo) {
            $this->deleteFile($photo->path);
        }

        $code = $pallet->code;

        // Delete the Pallet record; rely on DB cascade for child records
        $pallet->delete();

        return response()->json([
            'deleted' => true,
            'code'    => $code,
        ]);
    }

    // ── deleteOrder() ─────────────────────────────────────────────────────

    public function deleteOrder(Request $request, int $id): JsonResponse
    {
        $order = Order::with(['tickets.photos'])->findOrFail($id);

        // Delete storage files for all OrderTicketPhoto records
        foreach ($order->tickets as $ticket) {
            foreach ($ticket->photos as $photo) {
                $this->deleteFile($photo->path);
            }
        }

        $code = $order->code;

        // Delete the Order record; rely on DB cascade for child records
        $order->delete();

        return response()->json([
            'deleted' => true,
            'code'    => $code,
        ]);
    }
}
