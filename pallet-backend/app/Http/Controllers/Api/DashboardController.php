<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\OrderTicketPhoto;
use App\Models\Pallet;
use App\Models\PendingItem;
use Illuminate\Support\Facades\DB;

class DashboardController extends Controller
{
    public function index()
    {
        // ── Contadores principales ────────────────────────────────────────
        $openPallets = Pallet::where('status', 'open')->count();
        $openOrders  = Order::where('status', '!=', 'done')->count();

        // Unidades pendientes de distribuir en bases (pedidos no finalizados)
        $pendingUnits = (int) DB::selectOne("
            SELECT COALESCE(SUM(oi.qty), 0) - COALESCE(SUM(pboi_sum.assigned), 0) AS pending
            FROM order_items oi
            JOIN orders o ON o.id = oi.order_id
            LEFT JOIN (
                SELECT pboi.order_item_id, SUM(pboi.qty) AS assigned
                FROM pallet_base_order_items pboi
                GROUP BY pboi.order_item_id
            ) pboi_sum ON pboi_sum.order_item_id = oi.id
            WHERE o.status != 'done'
        ")->pending;

        // OCR consumido este mes
        $ocrThisMonth = OrderTicketPhoto::whereNotNull('ocr_processed_at')
            ->whereYear('ocr_processed_at', now()->year)
            ->whereMonth('ocr_processed_at', now()->month)
            ->count();

        // Pendientes (PendingItems sin resolver)
        $pendingCount = PendingItem::where('resolved', false)->count();

        // ── Último pedido abierto ─────────────────────────────────────────
        $lastOpenOrder = Order::where('status', '!=', 'done')
            ->orderByDesc('updated_at')
            ->with('pallets:id,code,status')
            ->first();

        // ── Último pallet abierto ─────────────────────────────────────────
        $lastOpenPallet = Pallet::where('status', 'open')
            ->orderByDesc('id')
            ->with('orders:orders.id,orders.code,orders.status')
            ->first();

        // ── Pedidos por día (últimos 30 días) ─────────────────────────────
        $ordersByDate = Order::select(
                DB::raw('DATE(created_at) as date'),
                DB::raw('COUNT(*) as total'),
                DB::raw("SUM(CASE WHEN status = 'done' THEN 1 ELSE 0 END) as done"),
                DB::raw("SUM(CASE WHEN status != 'done' THEN 1 ELSE 0 END) as open")
            )
            ->where('created_at', '>=', now()->subDays(29)->startOfDay())
            ->groupBy(DB::raw('DATE(created_at)'))
            ->orderBy('date', 'desc')
            ->get();

        return response()->json([
            'stats' => [
                'open_pallets'   => $openPallets,
                'open_orders'    => $openOrders,
                'pending_units'  => $pendingUnits,
                'ocr_this_month' => $ocrThisMonth,
            ],
            'pending_count'   => $pendingCount,
            'last_open_order'  => $lastOpenOrder,
            'last_open_pallet' => $lastOpenPallet,
            'orders_by_date'  => $ordersByDate,
        ]);
    }
}
