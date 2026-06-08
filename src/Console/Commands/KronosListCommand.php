<?php
namespace ZuqongTech\Kronos\Console\Commands;
use Illuminate\Console\Command;
use ZuqongTech\Kronos\Models\KronosWorkflow;
use ZuqongTech\Kronos\Models\KronosScheduledTask;
class KronosListCommand extends Command
{
    protected $signature = 'kronos:list';
    protected $description = 'List all registered Kronos workflows and scheduled tasks';
    public function handle(): int
    {
        $workflows = KronosWorkflow::all(['id', 'name', 'trigger_type', 'cron_expression', 'enabled']);
        $this->table(['ID', 'Name', 'Trigger', 'Cron', 'Enabled'], $workflows->map(fn($w) => [
            $w->id, $w->name, $w->trigger_type, $w->cron_expression ?? '-', $w->enabled ? '✔' : '✘',
        ]));
        return self::SUCCESS;
    }
}
