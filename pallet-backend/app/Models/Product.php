<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Product extends Model
{
    protected $fillable = ['ean', 'ean_last4', 'name', 'image_url', 'units_per_bulto'];

    /**
     * Devuelve un mapa EAN → Product (solo image_url + units_per_bulto)
     * para enriquecer ítems de pedido sin N+1.
     */
    public static function infoByEans(array $eans): \Illuminate\Support\Collection
    {
        if (empty($eans)) return collect();
        return static::whereIn('ean', $eans)
            ->get(['ean', 'image_url', 'units_per_bulto'])
            ->keyBy('ean');
    }
}
