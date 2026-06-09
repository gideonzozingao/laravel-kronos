<?php

namespace ZuqongTech\Kronos\Console\Commands;

use Illuminate\Console\Command;
use ZuqongTech\Kronos\Models\KronosScheduleRun;
use ZuqongTech\Kronos\Models\KronosWorkflowRun;

/**
 * Fix #22: prune old run history records per retention_days config.
 */
class KronosPruneCommand extends Command
{
    protected $signature = 'kronos:prune {--days= : Override config retention_days}';

    protected $description = 'Prune Kronos run history older than the configured retention period';

    public function handle(): int
    {
        $days = (int) ($this->option('days') ?? config('kronos.retention_days', 30));

        if ($days <= 0) {
            $this->warn('Retention days is 0 or negative — nothing pruned. Set KRONOS_RETENTION_DAYS in .env.');

            return self::SUCCESS;
        }

        $cutoff = now()->subDays($days);

        $workflowRuns = KronosWorkflowRun::where('created_at', '<', $cutoff)->count();
        KronosWorkflowRun::where('created_at', '<', $cutoff)->delete();

        $scheduleRuns = KronosScheduleRun::where('created_at', '<', $cutoff)->count();
        KronosScheduleRun::where('created_at', '<', $cutoff)->delete();

        $this->info(sprintf('✔ Pruned %s workflow run(s) and %s schedule run(s) older than %d day(s).', $workflowRuns, $scheduleRuns, $days));

        return self::SUCCESS;
    }
}
