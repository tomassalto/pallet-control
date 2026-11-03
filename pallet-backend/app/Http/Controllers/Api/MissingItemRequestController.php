<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\IndexMissingItemRequestsRequest;
use App\Http\Requests\StoreMissingItemRequestRequest;
use App\Http\Requests\UpdateMissingItemRequestRequest;
use App\Models\MissingItemRequest;
use App\Models\OrderItem;
use App\Services\FinalizationService;
use App\Services\MissingItemRequestService;
use Illuminate\Support\Facades\DB;

class MissingItemRequestController extends Controller
{
    public function __construct(
        private readonly MissingItemRequestService $missingItemRequestService,
        private readonly FinalizationService $finalizationService,
    ) {}

    // POST /public/orders/{token}/missing-items — sin auth, protegido por token opaco
    public function store(StoreMissingItemRequestRequest $request, string $token)
    {
        // El Form Request ya resolvió el token: un pedido desconocido ni
        // siquiera llega a validar (404 conserva su precedencia sobre 422).
        $order = $request->order();

        $data = $request->validated();

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
            return response()->json(['message' => 'Ya existe una solicitud reciente para este producto'], 409);
        }

        return DB::transaction(function () use ($order, $data) {
            // El estado se relee bajo lock: un pedido finalizado es inmutable.
            $lockedOrder = $this->finalizationService->lockOrderForWrite($order->id);
            if (is_array($lockedOrder)) {
                return response()->json($lockedOrder['error'], $lockedOrder['status']);
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
        });
    }

    // GET /admin/missing-item-requests — admin autenticado
    public function index(IndexMissingItemRequestsRequest $request)
    {
        $filters = $request->validated();

        $items = MissingItemRequest::with(['order', 'orderItem.product', 'reviewer'])
            ->when(
                isset($filters['status']),
                fn ($q) => $q->where('status', $filters['status'])
            )
            ->orderByRaw("CASE WHEN status = 'pending_review' THEN 0 ELSE 1 END")
            ->orderBy('created_at', 'desc')
            ->get()
            ->map(fn ($r) => $this->format($r));

        return response()->json($items);
    }

    // PATCH /admin/missing-item-requests/{missingItemRequest} — admin autenticado
    public function update(UpdateMissingItemRequestRequest $request, MissingItemRequest $missingItemRequest)
    {
        $data = $request->validated();

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
