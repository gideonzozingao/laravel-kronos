<?php

namespace ZuqongTech\Kronos\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use ZuqongTech\Kronos\Database\Factories\KronosWorkflowRunFactory;
use ZuqongTech\Kronos\Engine\KronosOrchestrator;
use ZuqongTech\Kronos\Enums\RunStatus;

class KronosWorkflowRun extends Model
{
    use HasFactory;

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
        'context' => 'array',
        'started_at' => 'datetime',
        'finished_at' => 'datetime',
        // Fix #16: cast run_id to string consistently
        'run_id' => 'string',
        'status' => RunStatus::class,
    ];

    protected static function newFactory(): KronosWorkflowRunFactory
    {
        return KronosWorkflowRunFactory::new();
    }

    public function workflow(): BelongsTo
    {
        return $this->belongsTo(KronosWorkflow::class, 'workflow_id');
    }

    public function stepRuns(): HasMany
    {
        return $this->hasMany(KronosStepRun::class, 'workflow_run_id');
    }

    /**
     * Fix #9: compare enum values, not enum instances, for reliability.
     */
    public function isTerminal(): bool
    {
        return in_array($this->status->value, [
            RunStatus::Completed->value,
            RunStatus::Failed->value,
            RunStatus::Cancelled->value,
        ], strict: true);
    }

    /**
     * Cancel this run. Delegates to the orchestrator for full teardown.
     * Fix #23: cancel() on the model as a convenience method.
     */
    public function cancel(string $reason = 'Cancelled by operator'): void
    {
        app(KronosOrchestrator::class)->cancel($this, $reason);
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
