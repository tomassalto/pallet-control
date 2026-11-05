<?php

namespace App\Models;

use App\Models\Concerns\RejectsInvalidIntegerRouteBindings;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

class OrderTicketPhoto extends Model
{
    use RejectsInvalidIntegerRouteBindings;

    protected $fillable = [
        'ticket_id',
        'path',
        'original_name',
        'note',
        'order_index',
        'ocr_data',
        'ocr_processed_at',
        'ocr_log',
    ];

    protected $appends = ['url'];

    protected function casts(): array
    {
        return [
            'ocr_data' => 'array',
            'ocr_processed_at' => 'datetime',
        ];
    }

    public function getUrlAttribute(): string
    {
        return Storage::disk(config('filesystems.default', 'public'))->url($this->path);
    }

    /** @return BelongsTo<OrderTicket, $this> */
    public function ticket(): BelongsTo
    {
        return $this->belongsTo(OrderTicket::class, 'ticket_id');
    }
}
