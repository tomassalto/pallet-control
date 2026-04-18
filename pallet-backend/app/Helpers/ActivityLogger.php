<?php

namespace App\Helpers;

use App\Models\ActivityLog;
use Illuminate\Support\Facades\Auth;

class ActivityLogger
{
    public static function log(
        string $action,
        string $entityType,
        ?int $entityId,
        string $description,
        ?int $palletId = null,
        ?array $oldValues = null,
        ?array $newValues = null,
        ?int $orderId = null
    ): ActivityLog {
        return ActivityLog::create([
            'user_id' => Auth::id(),
            'action' => $action,
            'entity_type' => $entityType,
            'entity_id' => $entityId,
            'pallet_id' => $palletId,
            'order_id' => $orderId,
            'description' => $description,
            'old_values' => $oldValues,
            'new_values' => $newValues,
        ]);
    }
}
