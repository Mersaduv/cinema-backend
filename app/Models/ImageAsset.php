<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ImageAsset extends Model
{
    protected $table = 'image_assets';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'srcset_json' => 'array',
            'variants_json' => 'array',
            'metadata' => 'array',
            'is_primary' => 'boolean',
            'year' => 'integer',
            'width' => 'integer',
            'height' => 'integer',
            'file_size' => 'integer',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function toImageObject(): array
    {
        return [
            'original' => $this->original_public_url,
            'blur' => $this->blur_url,
            'src' => $this->src_url,
            'srcset' => $this->srcset_json ?? [],
            'srcset_string' => $this->srcset_string,
            'width' => $this->width,
            'height' => $this->height,
            'mime_type' => $this->mime_type,
        ];
    }

    public function isReady(): bool
    {
        return $this->status === 'ready';
    }
}
