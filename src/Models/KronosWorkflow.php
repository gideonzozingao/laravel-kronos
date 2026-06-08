<?php

namespace ZuqongTech\Kronos\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use ZuqongTech\Kronos\Enums\RunStatus;

class KronosWorkflow extends Model
{
    protected $table = 'kronos_workflows';

    protected $fillable = [
        'name',
        'trigger_type',
        'cron_expression',
        'timezone',
        'definition',
        'enabled',
    ];

    protected $casts = [
        'definition' => 'array',
        'enabled'    => 'boolean',
    ];

    public function runs(): HasMany
    {
        return $this->hasMany(KronosWorkflowRun::class, 'workflow_id');
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