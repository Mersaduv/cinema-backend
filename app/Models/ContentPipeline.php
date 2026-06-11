<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ContentPipeline extends Model
{
    protected $fillable = [
        'entity_type',
        'entity_id',
        'status',
        'triggered_by',
        'triggered_at',
        'started_at',
        'completed_at',
        'failed_at',
        'total_tasks',
        'completed_tasks',
        'failed_tasks',
        'error_summary',
    ];

    protected function casts(): array
    {
        return [
            'triggered_at' => 'datetime',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
            'failed_at' => 'datetime',
        ];
    }

    public function tasks(): HasMany
    {
        return $this->hasMany(ContentPipelineTask::class, 'pipeline_id');
    }
}
