<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ContentPipelineTask extends Model
{
    protected $fillable = [
        'pipeline_id',
        'task_key',
        'task_label',
        'priority',
        'is_critical',
        'status',
        'started_at',
        'completed_at',
        'failed_at',
        'laravel_job_id',
        'attempt_count',
        'error_message',
        'result_summary',
    ];

    protected function casts(): array
    {
        return [
            'is_critical' => 'boolean',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
            'failed_at' => 'datetime',
            'result_summary' => 'array',
        ];
    }

    public function pipeline(): BelongsTo
    {
        return $this->belongsTo(ContentPipeline::class, 'pipeline_id');
    }
}
