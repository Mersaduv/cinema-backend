<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class MediaAsset extends Model
{
    protected $fillable = [
        'kind',
        'source_path',
        'source_is_external',
        'managed_relative_directory',
        'hls_output_relative_directory',
        'master_playlist_relative_path',
        'processing_status',
        'processing_error',
        'last_processed_at',
    ];

    protected function casts(): array
    {
        return [
            'source_is_external' => 'boolean',
            'last_processed_at' => 'datetime',
        ];
    }

    public function mediable(): MorphTo
    {
        return $this->morphTo();
    }

    public function variants(): HasMany
    {
        return $this->hasMany(HlsVariant::class);
    }

    public function processingRuns(): HasMany
    {
        return $this->hasMany(MediaProcessingRun::class);
    }
}
