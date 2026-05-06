<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Order extends Model
{
    protected $fillable = ['customer_id', 'code', 'status', 'note'];

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function pallets(): BelongsToMany
    {
        return $this->belongsToMany(Pallet::class)->withTimestamps();
    }

    public function items(): HasMany
    {
        return $this->hasMany(OrderItem::class);
    }

    public function tickets(): HasMany
    {
        return $this->hasMany(OrderTicket::class);
    }
}
