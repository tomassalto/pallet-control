<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\OrderItem;
use App\Models\PendingItem;
use App\Helpers\ActivityLogger;
use Illuminate\Http\Request;

class PendingItemController extends Controller
{
    // GET /pending-items
    public function index(Request $request)
    {
        $items = PendingItem::with([
            'order',
            'orderItem.product',
            'creator',
            'resolver',
        ])
            ->when($request->filled('status'), fn($q) => $q->where('status', $request->string('status')))
            ->orderByRaw("CASE WHEN status = 'pending' THEN 0 ELSE 1 END")
            ->orderBy('created_at', 'desc')
            ->get()
            ->map(fn($item) => $this->format($item));

        return response()->json($items);
    }

    // GET /pending-items/summary
    public function summary()
    {
        return response()->json([
            'pending_count' => PendingItem::where('status', 'pending')->count(),
        ]);
    }

    // POST /pending-items
    public function store(Request $request)
    {
        $data = $request->validate([
            'order_id'      => ['required', 'exists:orders,id'],
            'order_item_id' => ['required', 'exists:order_items,id'],
            'qty_missing'   => ['required', 'integer', 'min:1'],
            'note'          => ['nullable', 'string', 'max:1000'],
        ]);

        // Verificar que el item pertenece al pedido
        $orderItem = OrderItem::findOrFail($data['order_item_id']);
        if ($orderItem->order_id !== (int) $data['order_id']) {
            return response()->json(['message' => 'El producto no pertenece a ese pedido'], 422);
        }

        $pending = PendingItem::create([
            ...$data,
            'status'     => 'pending',
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
    public function update(Request $request, PendingItem $pendingItem)
    {
        $data = $request->validate([
            'status'      => ['sometimes', 'in:pending,resolved'],
            'qty_missing' => ['sometimes', 'integer', 'min:1'],
            'note'        => ['sometimes', 'nullable', 'string', 'max:1000'],
        ]);

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
        $orderId     = $pendingItem->order_id;
        $qty         = $pendingItem->qty_missing;

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
            'id'               => $item->id,
            'order_id'         => $item->order_id,
            'order_code'       => $item->order?->code,
            'order_item_id'    => $item->order_item_id,
            'description'      => $item->orderItem?->description,
            'ean'              => $item->orderItem?->ean,
            'image_url'        => $item->orderItem?->product?->image_url ?? null,
            'original_qty'     => $item->orderItem?->qty,
            'qty_missing'      => $item->qty_missing,
            'note'             => $item->note,
            'status'           => $item->status,
            'resolved_at'      => $item->resolved_at?->toISOString(),
            'resolved_by_name' => $item->resolver?->name,
            'created_by_name'  => $item->creator?->name,
            'created_at'       => $item->created_at?->toISOString(),
        ];
    }
}
