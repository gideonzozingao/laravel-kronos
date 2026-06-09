<?php

namespace ZuqongTech\Kronos\Console\Commands;

use Illuminate\Console\Command;
use ZuqongTech\Kronos\Engine\KronosOrchestrator;
use ZuqongTech\Kronos\Models\KronosWorkflowRun;

/**
 * Fix #23: cancel a running or pending workflow run.
 */
class KronosCancelCommand extends Command
{
    protected $signature = 'kronos:cancel
                            {run_id : The UUID of the workflow run to cancel}
                            {--reason= : Optional cancellation reason}';

    protected $description = 'Cancel a running or pending Kronos workflow run';

    public function handle(KronosOrchestrator $orchestrator): int
    {
        $runId = $this->argument('run_id');
        $reason = $this->option('reason') ?? 'Cancelled via kronos:cancel';

        $run = KronosWorkflowRun::where('run_id', $runId)->first();

        if (!$run) {
            $this->error("No workflow run found with ID [{$runId}].");

            return self::FAILURE;
        }

        if ($run->isTerminal()) {
            $this->warn("Run [{$runId}] is already in a terminal state ({$run->status->value}). Nothing to cancel.");

            return self::SUCCESS;
        }

        $orchestrator->cancel($run, $reason);

        $this->info("✔ Run [{$runId}] cancelled.");

        return self::SUCCESS;
    }
}
