<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ContentPlayStat extends Model
{
    protected $fillable = [
        'media_type',
        'media_id',
        'plays_7d',
        'plays_30d',
        'aggregated_at',
    ];

    protected function casts(): array
    {
        return [
            'media_id' => 'integer',
            'plays_7d' => 'integer',
            'plays_30d' => 'integer',
            'aggregated_at' => 'datetime',
        ];
    }
}
