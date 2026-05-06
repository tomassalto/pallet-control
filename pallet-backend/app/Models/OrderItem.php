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
        'price',
        'desc_medio_pago',
        'is_controlled',
        'status',
        'done_qty',
    ];

    protected function casts(): array
    {
        return [
            'price'           => 'float',
            'desc_medio_pago' => 'float',
            'is_controlled'   => 'boolean',
        ];
    }

    public function order()
    {
        return $this->belongsTo(Order::class);
    }

    public function product()
    {
        // OrderItem no tiene product_id; se relaciona por EAN (clave natural)
        return $this->belongsTo(Product::class, 'ean', 'ean');
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
