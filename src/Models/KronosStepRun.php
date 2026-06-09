<?php

namespace ZuqongTech\Kronos\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use ZuqongTech\Kronos\Database\Factories\KronosStepRunFactory;
use ZuqongTech\Kronos\Enums\StepStatus;

class KronosStepRun extends Model
{
    use HasFactory; // Fix #21

    protected $table = 'kronos_step_runs';

    protected $fillable = [
        'workflow_run_id',
        'step_name',
        'status',
        'output',
        'attempt',
        'started_at',
        'finished_at',
        'exception',
    ];

    protected $casts = [
        'output' => 'array',
        'started_at' => 'datetime',
        'finished_at' => 'datetime',
        'status' => StepStatus::class,
    ];

    protected static function newFactory(): KronosStepRunFactory
    {
        return KronosStepRunFactory::new();
    }

    public function run(): BelongsTo
    {
        return $this->belongsTo(KronosWorkflowRun::class, 'workflow_run_id');
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
        return $this->status === StepStatus::Completed;
    }
}
