<?php

namespace App\Models;

use App\Models\Concerns\RejectsInvalidIntegerRouteBindings;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class OrderTicket extends Model
{
    use RejectsInvalidIntegerRouteBindings;

    protected $fillable = [
        'order_id',
        'code',
        'note',
    ];

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function photos(): HasMany
    {
        return $this->hasMany(OrderTicketPhoto::class, 'ticket_id')->orderBy('order_index');
    }
}
