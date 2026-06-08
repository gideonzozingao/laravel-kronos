<?php
namespace ZuqongTech\Kronos\Console\Commands;
use Illuminate\Console\Command;
class KronosInstallCommand extends Command
{
    protected $signature = 'kronos:install';
    protected $description = 'Install Kronos — publish config, run migrations';
    public function handle(): int
    {
        $this->info('Installing Kronos...');
        $this->call('vendor:publish', ['--tag' => 'kronos-config']);
        $this->call('vendor:publish', ['--tag' => 'kronos-migrations']);
        $this->call('migrate');
        $this->info('✔ Kronos installed successfully.');
        return self::SUCCESS;
    }
}
