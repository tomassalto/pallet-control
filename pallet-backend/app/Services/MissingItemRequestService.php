<?php

namespace App\Services;

use App\Helpers\ActivityLogger;
use App\Models\MissingItemRequest;
use App\Models\OrderItem;
use App\Models\PendingItem;
use Illuminate\Support\Facades\DB;

class MissingItemRequestService
{
    /**
     * @return array{request: MissingItemRequest}|array{error: array{message: string}, status: int}
     */
    public function review(MissingItemRequest $request, string $status, int $reviewerId): array
    {
        return DB::transaction(function () use ($request, $status, $reviewerId) {
            $lockedRequest = MissingItemRequest::query()
                ->lockForUpdate()
                ->findOrFail($request->id);

            if ($lockedRequest->status !== 'pending_review') {
                return [
                    'error' => ['message' => 'La solicitud ya fue revisada.'],
                    'status' => 409,
                ];
            }

            $lockedRequest->update([
                'status' => $status,
                'reviewed_at' => now(),
                'reviewed_by' => $reviewerId,
            ]);

            $description = OrderItem::query()
                ->findOrFail($lockedRequest->order_item_id)
                ->description;

            if ($status === 'approved') {
                $pending = PendingItem::create([
                    'order_id' => $lockedRequest->order_id,
                    'order_item_id' => $lockedRequest->order_item_id,
                    'qty_missing' => $lockedRequest->qty_missing,
                    'note' => $lockedRequest->note,
                    'status' => 'pending',
                    'created_by' => $reviewerId,
                ]);

                ActivityLogger::log(
                    action: 'missing_item_request_approved',
                    entityType: 'missing_item_request',
                    entityId: $lockedRequest->id,
                    description: "Solicitud aprobada → pendiente creado: {$description} ({$lockedRequest->qty_missing} unid.)",
                    newValues: ['pending_item_id' => $pending->id, 'qty_missing' => $lockedRequest->qty_missing],
                    orderId: $lockedRequest->order_id,
                );
            } else {
                ActivityLogger::log(
                    action: 'missing_item_request_rejected',
                    entityType: 'missing_item_request',
                    entityId: $lockedRequest->id,
                    description: "Solicitud rechazada: {$description}",
                    orderId: $lockedRequest->order_id,
                );
            }

            return ['request' => $lockedRequest];
        });
    }
}
