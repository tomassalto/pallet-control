<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PalletBase extends Model
{
    protected $fillable = [
        'pallet_id',
        'name',
        'note',
    ];

    public function pallet(): BelongsTo
    {
        return $this->belongsTo(Pallet::class);
    }

    public function photos(): HasMany
    {
        return $this->hasMany(PalletBasePhoto::class, 'base_id')->orderByDesc('created_at');
    }

    public function orderItems(): BelongsToMany
    {
        return $this->belongsToMany(
            \App\Models\OrderItem::class,
            'pallet_base_order_items',
            'base_id', // foreign key de PalletBase en la tabla pivot
            'order_item_id' // foreign key de OrderItem en la tabla pivot
        )
            ->withPivot('qty')
            ->withTimestamps();
    }
}
