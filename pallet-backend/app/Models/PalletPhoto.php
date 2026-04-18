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

    public function pallet()
    {
        return $this->belongsTo(Pallet::class);
    }
}
