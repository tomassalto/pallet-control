<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OrderTicketPhoto extends Model
{
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
            'ocr_data'         => 'array',
            'ocr_processed_at' => 'datetime',
        ];
    }

    public function getUrlAttribute(): string
    {
        return '/storage/' . $this->path;
    }

    public function ticket(): BelongsTo
    {
        return $this->belongsTo(OrderTicket::class, 'ticket_id');
    }
}
