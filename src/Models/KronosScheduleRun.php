<?php

namespace ZuqongTech\Kronos\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class KronosScheduleRun extends Model
{
    protected $table = 'kronos_schedule_runs';

    protected $fillable = [
        'task_id',
        'status',
        'output',
        'exit_code',
        'started_at',
        'finished_at',
    ];

    protected $casts = [
        'started_at'  => 'datetime',
        'finished_at' => 'datetime',
    ];

    public function task(): BelongsTo
    {
        return $this->belongsTo(KronosScheduledTask::class, 'task_id');
    }

    public function getDurationAttribute(): ?float
    {
        if (!$this->started_at || !$this->finished_at) {
            return null;
        }

        return $this->started_at->diffInSeconds($this->finished_at);
    }

    public function isSuccessful(): bool
    {
        return $this->status === 'completed' && $this->exit_code === 0;
    }
}