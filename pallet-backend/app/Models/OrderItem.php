<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class OrderItem extends Model
{
    protected $fillable = [
        'order_id',
        'ean',
        'ean_last4',
        'description',
        'qty',
        'status',
        'done_qty',
    ];

    public function order()
    {
        return $this->belongsTo(Order::class);
    }

    public function product()
    {
        return $this->belongsTo(Product::class);
    }

    public function bases(): BelongsToMany
    {
        return $this->belongsToMany(
            \App\Models\PalletBase::class,
            'pallet_base_order_items',
            'order_item_id',
            'base_id'
        )
            ->withPivot('qty')
            ->withTimestamps();
    }
}
