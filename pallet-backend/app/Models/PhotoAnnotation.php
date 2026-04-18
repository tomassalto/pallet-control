<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PhotoAnnotation extends Model
{
    protected $fillable = [
        'photo_id',
        'order_item_id',
        'text',
        'x',
        'y',
        'font_size',
        'color',
    ];

    protected $casts = [
        'x' => 'float',
        'y' => 'float',
        'font_size' => 'integer',
    ];

    public function photo(): BelongsTo
    {
        return $this->belongsTo(PalletBasePhoto::class, 'photo_id');
    }

    public function orderItem(): BelongsTo
    {
        return $this->belongsTo(OrderItem::class, 'order_item_id');
    }
}
