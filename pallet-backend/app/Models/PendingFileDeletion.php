<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PendingFileDeletion extends Model
{
    protected $fillable = [
        'disk',
        'path',
        'reason',
        'attempts',
        'last_error',
        'available_at',
        'completed_at',
    ];

    protected function casts(): array
    {
        return [
            'attempts' => 'integer',
            'available_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }
}
