<?php

namespace Pine\Commerce\Console;

use Illuminate\Console\Command;
use Pine\Commerce\Scheduling\Scheduler;

/** `php artisan commerce:schedule:task {task}` – run one scheduled task now (core or client) (recorded as a manual run). */
class ScheduleTaskCommand extends Command
{
    protected $signature = 'commerce:schedule:task {task : Task key, e.g. carts.abandoned-emails (see commerce:schedule:status)}';

    protected $description = 'Run one scheduled task now (core or client)';

    public function handle(): int
    {
        $key = (string) $this->argument('task');
        if (! Scheduler::has($key)) {
            $this->components->error("Unknown task [{$key}]. Tasks: ".implode(', ', array_keys(Scheduler::tasks())));

            return self::FAILURE;
        }
        if (! Scheduler::configured($key)) {
            $this->components->warn("Task [{$key}] is switched off in config commerce.scheduler – running it anyway.");
        }
        $result = Scheduler::run($key, 'manual');
        $line = "{$key}: {$result['status']} – {$result['summary']} ({$result['ms']} ms)";
        match ($result['status']) {
            'ok' => $this->components->info($line),
            'skipped' => $this->components->warn($line),
            default => $this->components->error($line),
        };

        return $result['status'] === 'failed' ? self::FAILURE : self::SUCCESS;
    }
}
