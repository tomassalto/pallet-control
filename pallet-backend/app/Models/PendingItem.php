<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PendingItem extends Model
{
    protected $fillable = [
        'order_id',
        'order_item_id',
        'qty_missing',
        'note',
        'status',
        'resolved_at',
        'resolved_by',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'resolved_at' => 'datetime',
        ];
    }

    /**
     * H-050: el segmento de ruta llega sin tipo; un id que no es un entero
     * dentro del rango bigint no debe llegar a la comparación por `id`
     * (PostgreSQL responde SQLSTATE[22P02] → 500). Devolver null reproduce
     * el 404 estándar del binding implícito en el mismo punto de la
     * precedencia (auth → binding → user.active → has.role → validación),
     * sin consultar la base.
     */
    public function resolveRouteBinding($value, $field = null)
    {
        $id = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]);

        if ($id === false) {
            return null;
        }

        return $this->resolveRouteBindingQuery($this, $id, $field)->first();
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function orderItem(): BelongsTo
    {
        return $this->belongsTo(OrderItem::class);
    }

    public function resolver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'resolved_by');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
