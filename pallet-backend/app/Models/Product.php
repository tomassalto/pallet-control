<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Product extends Model
{
    protected $fillable = ['ean', 'ean_last4', 'name', 'image_url'];

    public function movements(): HasMany
    {
        return $this->hasMany(Movement::class);
    }
}
