<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\MissingItemRequest;
use App\Models\Order;
use App\Models\OrderItem;
use App\Services\MissingItemRequestService;
use Illuminate\Http\Request;

class MissingItemRequestController extends Controller
{
    public function __construct(
        private readonly MissingItemRequestService $missingItemRequestService
    ) {}

    // POST /public/orders/{token}/missing-items — sin auth, protegido por token opaco
    public function store(Request $request, string $token)
    {
        $order = Order::where('public_token', $token)->first();
        if (! $order) {
            return response()->json(['message' => 'Pedido no encontrado'], 404);
        }

        $data = $request->validate([
            'order_item_id' => ['required', 'exists:order_items,id'],
            'qty_missing' => ['required', 'integer', 'min:1'],
            'requester_name' => ['nullable', 'string', 'max:100'],
            'note' => ['nullable', 'string', 'max:500'],
        ]);

        $item = OrderItem::findOrFail($data['order_item_id']);

        if ($item->order_id !== $order->id) {
            return response()->json(['message' => 'El producto no pertenece a este pedido'], 422);
        }

        if ($data['qty_missing'] > $item->qty) {
            return response()->json(['message' => 'La cantidad no puede superar la del pedido'], 422);
        }

        // Anti-duplicado: misma solicitud en los últimos 10 minutos
        $recentExists = MissingItemRequest::where('order_item_id', $data['order_item_id'])
            ->where('status', 'pending_review')
            ->where('created_at', '>=', now()->subMinutes(10))
            ->exists();

        if ($recentExists) {
            return response()->json(['message' => 'Ya existe una solicitud reciente para este producto'], 422);
        }

        $req = MissingItemRequest::create([
            'order_id' => $order->id,
            'order_item_id' => $data['order_item_id'],
            'qty_missing' => $data['qty_missing'],
            'requester_name' => $data['requester_name'] ?? null,
            'note' => $data['note'] ?? null,
            'status' => 'pending_review',
        ]);

        return response()->json(['message' => 'Solicitud recibida', 'id' => $req->id], 201);
    }

    // GET /admin/missing-item-requests — admin autenticado
    public function index(Request $request)
    {
        $items = MissingItemRequest::with(['order', 'orderItem.product', 'reviewer'])
            ->when(
                $request->filled('status'),
                fn ($q) => $q->where('status', $request->string('status'))
            )
            ->orderByRaw("CASE WHEN status = 'pending_review' THEN 0 ELSE 1 END")
            ->orderBy('created_at', 'desc')
            ->get()
            ->map(fn ($r) => $this->format($r));

        return response()->json($items);
    }

    // PATCH /admin/missing-item-requests/{missingItemRequest} — admin autenticado
    public function update(Request $request, MissingItemRequest $missingItemRequest)
    {
        $data = $request->validate([
            'status' => ['required', 'in:approved,rejected'],
        ]);

        $result = $this->missingItemRequestService->review(
            $missingItemRequest,
            $data['status'],
            $request->user()->id,
        );

        if (isset($result['error'])) {
            return response()->json($result['error'], $result['status']);
        }

        return response()->json(
            $this->format($result['request']->load(['order', 'orderItem.product', 'reviewer']))
        );
    }

    // ── helpers ──────────────────────────────────────────────────────────────

    private function format(MissingItemRequest $r): array
    {
        return [
            'id' => $r->id,
            'order_id' => $r->order_id,
            'order_code' => $r->order?->code,
            'order_item_id' => $r->order_item_id,
            'description' => $r->orderItem?->description,
            'ean' => $r->orderItem?->ean,
            'image_url' => $r->orderItem?->product?->image_url ?? null,
            'units_per_bulto' => $r->orderItem?->product?->units_per_bulto ?? null,
            'original_qty' => $r->orderItem?->qty,
            'qty_missing' => $r->qty_missing,
            'requester_name' => $r->requester_name,
            'note' => $r->note,
            'status' => $r->status,
            'reviewed_at' => $r->reviewed_at?->toISOString(),
            'reviewed_by_name' => $r->reviewer?->name,
            'created_at' => $r->created_at?->toISOString(),
        ];
    }
}
