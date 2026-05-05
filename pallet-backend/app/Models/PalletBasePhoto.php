<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Storage;

class PalletBasePhoto extends Model
{
    protected $fillable = [
        'base_id',
        'path',
        'original_name',
        'note',
    ];

    protected $appends = ['url'];

    public function getUrlAttribute(): string
    {
        return Storage::disk(config('filesystems.default', 'public'))->url($this->path);
    }

    public function base(): BelongsTo
    {
        return $this->belongsTo(PalletBase::class, 'base_id');
    }

    public function annotations(): HasMany
    {
        return $this->hasMany(PhotoAnnotation::class, 'photo_id');
    }
}
