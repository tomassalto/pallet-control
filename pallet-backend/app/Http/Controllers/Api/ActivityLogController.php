<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

class ActivityLogController extends Controller
{
    // GET /activity-logs
    public function index(Request $request)
    {
        $limit = $request->integer('limit', 500);

        $logs = \App\Models\ActivityLog::with(['user:id,name', 'pallet:id,code', 'order:id,code'])
            ->orderByDesc('created_at')
            ->limit($limit)
            ->get()
            ->map(function ($log) {
                $context = [];

                if ($log->pallet) {
                    $context[] = "Pallet: {$log->pallet->code}";
                }

                if ($log->order) {
                    $context[] = "Pedido: {$log->order->code}";
                }

                return [
                    'id' => $log->id,
                    'action' => $log->action,
                    'entity_type' => $log->entity_type,
                    'description' => $log->description,
                    'user_name' => $log->user?->name ?? 'Sistema',
                    'context' => implode(' | ', $context),
                    'created_at' => $log->created_at->format('Y-m-d H:i:s'),
                    'created_at_formatted' => $log->created_at->format('d/m/Y H:i:s'),
                    'pallet_id' => $log->pallet_id,
                    'order_id' => $log->order_id,
                ];
            });

        return response()->json([
            'logs' => $logs,
            'total' => $logs->count(),
        ]);
    }
}
