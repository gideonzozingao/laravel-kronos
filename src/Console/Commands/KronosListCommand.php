<?php

namespace ZuqongTech\Kronos\Console\Commands;

use Illuminate\Console\Command;
use ZuqongTech\Kronos\Models\KronosScheduledTask;
use ZuqongTech\Kronos\Models\KronosWorkflow;

/**
 * Fix #15: added missing use statement for KronosScheduledTask.
 */
class KronosListCommand extends Command
{
    protected $signature = 'kronos:list {--workflows} {--tasks}';

    protected $description = 'List all registered Kronos workflows and scheduled tasks';

    public function handle(): int
    {
        $showAll = !$this->option('workflows') && !$this->option('tasks');
        $showWorkflows = $showAll || $this->option('workflows');
        $showTasks = $showAll || $this->option('tasks');

        if ($showWorkflows) {
            $this->info('Workflows:');
            $workflows = KronosWorkflow::all(['id', 'name', 'trigger_type', 'cron_expression', 'enabled']);
            $this->table(
                ['ID', 'Name', 'Trigger', 'Cron', 'Enabled'],
                $workflows->map(fn ($w): array => [
                    $w->id,
                    $w->name,
                    $w->trigger_type,
                    $w->cron_expression ?? '-',
                    $w->enabled ? '✔' : '✘',
                ]),
            );
        }

        if ($showTasks) {
            $this->newLine();
            $this->info('Scheduled Tasks:');
            $tasks = KronosScheduledTask::all(['id', 'name', 'command', 'cron_expression', 'enabled']);
            $this->table(
                ['ID', 'Name', 'Command', 'Cron', 'Enabled'],
                $tasks->map(fn ($t): array => [
                    $t->id,
                    $t->name,
                    $t->command,
                    $t->cron_expression,
                    $t->enabled ? '✔' : '✘',
                ]),
            );
        }

        return self::SUCCESS;
    }
}
