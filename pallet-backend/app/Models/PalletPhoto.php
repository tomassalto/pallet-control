<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PalletPhoto extends Model
{
    protected $fillable = [
        'pallet_id',
        'path',
        'original_name',
        'note',
    ];

    protected $appends = ['url'];

    public function getUrlAttribute(): string
    {
        return '/storage/' . $this->path;
    }

    public function pallet()
    {
        return $this->belongsTo(Pallet::class);
    }
}
