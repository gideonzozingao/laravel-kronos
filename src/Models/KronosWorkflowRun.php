<?php

namespace ZuqongTech\Kronos\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use ZuqongTech\Kronos\Enums\RunStatus;

class KronosWorkflowRun extends Model
{
    protected $table = 'kronos_workflow_runs';

    protected $fillable = [
        'workflow_id',
        'run_id',
        'status',
        'context',
        'error',
        'started_at',
        'finished_at',
    ];

    protected $casts = [
        'context'     => 'array',
        'started_at'  => 'datetime',
        'finished_at' => 'datetime',
        'status'      => RunStatus::class,
    ];

    public function workflow(): BelongsTo
    {
        return $this->belongsTo(KronosWorkflow::class, 'workflow_id');
    }

    public function stepRuns(): HasMany
    {
        return $this->hasMany(KronosStepRun::class, 'workflow_run_id');
    }

    public function isTerminal(): bool
    {
        return in_array($this->status, [
            RunStatus::Completed,
            RunStatus::Failed,
            RunStatus::Cancelled,
        ]);
    }

    public function getDurationAttribute(): ?float
    {
        if (!$this->started_at || !$this->finished_at) {
            return null;
        }
        return $this->started_at->diffInSeconds($this->finished_at);
    }

    public function scopeRunning($query)
    {
        return $query->where('status', RunStatus::Running->value);
    }

    public function scopeFailed($query)
    {
        return $query->where('status', RunStatus::Failed->value);
    }
}