<?php

namespace App\Models;

use App\Models\Concerns\RejectsInvalidIntegerRouteBindings;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;

class PalletPhoto extends Model
{
    use RejectsInvalidIntegerRouteBindings;

    protected $fillable = [
        'pallet_id',
        'path',
        'original_name',
        'note',
    ];

    protected $appends = ['url'];

    public function getUrlAttribute(): string
    {
        return Storage::disk(config('filesystems.default', 'public'))->url($this->path);
    }

    public function pallet()
    {
        return $this->belongsTo(Pallet::class);
    }
}
