<?php

namespace App\Http\Controllers\Api;

use App\Helpers\ActivityLogger;
use App\Http\Controllers\Controller;
use App\Http\Requests\IndexPendingItemsRequest;
use App\Http\Requests\StorePendingItemRequest;
use App\Http\Requests\UpdatePendingItemRequest;
use App\Models\OrderItem;
use App\Models\PendingItem;

class PendingItemController extends Controller
{
    // GET /pending-items
    public function index(IndexPendingItemsRequest $request)
    {
        $filters = $request->validated();
        $perPage = (int) ($filters['per_page'] ?? 25);

        $paginator = PendingItem::with([
            'order',
            'orderItem.product',
            'creator',
            'resolver',
        ])
            ->when(isset($filters['status']), fn ($q) => $q->where('status', $filters['status']))
            ->orderByRaw("CASE WHEN status = 'pending' THEN 0 ELSE 1 END")
            ->orderBy('created_at', 'desc')
            ->orderBy('id', 'desc')
            ->paginate($perPage);

        return response()->json([
            'data' => collect($paginator->items())
                ->map(fn (PendingItem $item) => $this->format($item))
                ->values(),
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
            ],
        ]);
    }

    // GET /pending-items/summary
    public function summary()
    {
        return response()->json([
            'pending_count' => PendingItem::where('status', 'pending')->count(),
        ]);
    }

    // POST /pending-items
    public function store(StorePendingItemRequest $request)
    {
        $data = $request->validated();

        // Verificar que el item pertenece al pedido
        $orderItem = OrderItem::findOrFail($data['order_item_id']);
        if ($orderItem->order_id !== (int) $data['order_id']) {
            return response()->json(['message' => 'El producto no pertenece a ese pedido'], 422);
        }

        $pending = PendingItem::create([
            ...$data,
            'status' => 'pending',
            'created_by' => $request->user()->id,
        ]);

        ActivityLogger::log(
            action: 'pending_item_created',
            entityType: 'pending_item',
            entityId: $pending->id,
            description: "Pendiente creado: {$orderItem->description} (faltan {$pending->qty_missing} unid.) — pedido #{$pending->order->code}",
            newValues: ['order_id' => $pending->order_id, 'qty_missing' => $pending->qty_missing],
            orderId: $pending->order_id,
        );

        return response()->json(
            $this->format($pending->load(['order', 'orderItem.product', 'creator'])),
            201
        );
    }

    // PATCH /pending-items/{pendingItem}
    public function update(UpdatePendingItemRequest $request, PendingItem $pendingItem)
    {
        $data = $request->validated();

        $wasResolved = $pendingItem->status === 'resolved';

        if (isset($data['status'])) {
            if ($data['status'] === 'resolved' && ! $wasResolved) {
                $data['resolved_at'] = now();
                $data['resolved_by'] = $request->user()->id;
            } elseif ($data['status'] === 'pending' && $wasResolved) {
                $data['resolved_at'] = null;
                $data['resolved_by'] = null;
            }
        }

        $pendingItem->update($data);

        ActivityLogger::log(
            action: 'pending_item_updated',
            entityType: 'pending_item',
            entityId: $pendingItem->id,
            description: "Pendiente actualizado: {$pendingItem->orderItem->description}",
            newValues: $data,
            orderId: $pendingItem->order_id,
        );

        return response()->json(
            $this->format($pendingItem->load(['order', 'orderItem.product', 'creator', 'resolver']))
        );
    }

    // DELETE /pending-items/{pendingItem}
    public function destroy(PendingItem $pendingItem)
    {
        $description = $pendingItem->orderItem->description;
        $orderId = $pendingItem->order_id;
        $qty = $pendingItem->qty_missing;

        ActivityLogger::log(
            action: 'pending_item_deleted',
            entityType: 'pending_item',
            entityId: null,
            description: "Pendiente eliminado: {$description} ({$qty} unid.)",
            oldValues: ['qty_missing' => $qty],
            orderId: $orderId,
        );

        $pendingItem->delete();

        return response()->json(['message' => 'Pendiente eliminado']);
    }

    // ── helpers ──────────────────────────────────────────────────────────────

    private function format(PendingItem $item): array
    {
        return [
            'id' => $item->id,
            'order_id' => $item->order_id,
            'order_code' => $item->order?->code,
            'order_item_id' => $item->order_item_id,
            'description' => $item->orderItem?->description,
            'ean' => $item->orderItem?->ean,
            'image_url' => $item->orderItem?->product?->image_url ?? null,
            'units_per_bulto' => $item->orderItem?->product?->units_per_bulto ?? null,
            'original_qty' => $item->orderItem?->qty,
            'qty_missing' => $item->qty_missing,
            'note' => $item->note,
            'status' => $item->status,
            'resolved_at' => $item->resolved_at?->toISOString(),
            'resolved_by_name' => $item->resolver?->name,
            'created_by_name' => $item->creator?->name,
            'created_at' => $item->created_at?->toISOString(),
        ];
    }
}
