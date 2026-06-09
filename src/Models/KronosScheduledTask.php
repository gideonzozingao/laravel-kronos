<?php

namespace ZuqongTech\Kronos\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class KronosScheduledTask extends Model
{
    protected $table = 'kronos_scheduled_tasks';

    protected $fillable = [
        'name',
        'command',
        'cron_expression',
        'timezone',
        'enabled',
        'without_overlapping',
        'on_one_server',
        'run_in_background',
        'on_failure_webhook',
        'meta',
    ];

    protected $casts = [
        'enabled' => 'boolean',
        'without_overlapping' => 'boolean',
        'on_one_server' => 'boolean',
        'run_in_background' => 'boolean',
        'meta' => 'array',
    ];

    public function runs(): HasMany
    {
        return $this->hasMany(KronosScheduleRun::class, 'task_id');
    }

    public function latestRun(): HasMany
    {
        return $this->runs()->latest()->limit(1);
    }

    public function scopeEnabled($query)
    {
        return $query->where('enabled', true);
    }
}
