<?php
namespace ZuqongTech\Kronos\Console\Commands;
use Illuminate\Console\Command;
use ZuqongTech\Kronos\Models\KronosWorkflowRun;
use ZuqongTech\Kronos\Enums\RunStatus;
class KronosStatusCommand extends Command
{
    protected $signature = 'kronos:status {run_id? : Specific run UUID}';
    protected $description = 'Check the status of running or recent workflow runs';
    public function handle(): int
    {
        if ($runId = $this->argument('run_id')) {
            $run = KronosWorkflowRun::where('run_id', $runId)->with(['workflow', 'stepRuns'])->firstOrFail();
            $this->info("Workflow: {$run->workflow->name} | Status: {$run->status->value}");
            $this->table(['Step', 'Status', 'Attempt', 'Duration'], $run->stepRuns->map(fn($s) => [
                $s->step_name, $s->status->value, $s->attempt, $s->duration ? $s->duration . 's' : '-',
            ]));
        } else {
            $runs = KronosWorkflowRun::with('workflow')->latest()->limit(20)->get();
            $this->table(['Run ID', 'Workflow', 'Status', 'Started'], $runs->map(fn($r) => [
                $r->run_id, $r->workflow->name, $r->status->value, $r->started_at?->diffForHumans(),
            ]));
        }
        return self::SUCCESS;
    }
}
