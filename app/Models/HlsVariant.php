<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class HlsVariant extends Model
{
    protected $fillable = [
        'media_asset_id',
        'profile_key',
        'playlist_relative_path',
        'resolution',
        'bandwidth',
        'codecs',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    public function mediaAsset(): BelongsTo
    {
        return $this->belongsTo(MediaAsset::class);
    }
}
