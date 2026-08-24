<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany; // usado por bases() y photos()

class Pallet extends Model
{
    use HasFactory;

    protected $fillable = ['code', 'status', 'note', 'created_by'];

    public function orders(): BelongsToMany
    {
        return $this->belongsToMany(Order::class)->withTimestamps();
    }

    public function photos()
    {
        return $this->hasMany(PalletPhoto::class)->orderByDesc('created_at');
    }

    public function bases(): HasMany
    {
        return $this->hasMany(PalletBase::class);
    }
}
