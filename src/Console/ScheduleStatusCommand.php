<?php

namespace Pine\Commerce\Console;

use Illuminate\Console\Command;
use Pine\Commerce\Scheduling\Scheduler;

/**
 * `php artisan commerce:schedule:status` – whether cron is running (heartbeat) and every scheduled task (core and client) with its
 * schedule, state, last run (result) and next run. Read-only.
 */
class ScheduleStatusCommand extends Command
{
    protected $signature = 'commerce:schedule:status {--json : Print the status as JSON}';

    protected $description = 'Show the core scheduled tasks: cron heartbeat, last run and next run of each task';

    public function handle(): int
    {
        $beat = Scheduler::lastHeartbeat();
        $running = Scheduler::cronRunning();
        $rows = Scheduler::status();
        $fallback = (bool) config('commerce.scheduler.web_fallback', false);

        if ($this->option('json')) {
            $this->line(json_encode([
                'cron_running' => $running,
                'last_heartbeat' => $beat?->toIso8601String(),
                'web_fallback' => $fallback,
                'tasks' => array_map(fn ($r) => [
                    'key' => $r['key'], 'label' => $r['label'], 'source' => $r['core'] ? 'core' : 'client', 'cron' => $r['cron'], 'configured' => $r['configured'], 'skip' => $r['skip'],
                    'last_run' => $r['last'] ? ['at' => $r['last']['at']->toIso8601String()] + array_diff_key($r['last'], ['at' => 1]) : null,
                    'next_run' => $r['next']?->toIso8601String(),
                ], $rows),
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

            return self::SUCCESS;
        }

        if ($running) {
            $this->components->info('Cron is running: last heartbeat '.$beat->diffForHumans().'.');
        } else {
            $this->components->warn(($beat ? 'Cron is NOT running (last heartbeat '.$beat->diffForHumans().').' : 'Cron has never run schedule:run on this install.')
                .' Add: * * * * * cd '.base_path().' && php artisan schedule:run >> /dev/null 2>&1');
            $this->line('  Without cron: unpaid orders are cancelled on checkout visits'
                .($fallback ? '; other due tasks run after storefront requests (web fallback, at most every '.(Scheduler::WEB_FALLBACK_SECONDS / 60).' minutes).' : '; other tasks wait for cron (commerce.scheduler.web_fallback is off).'));
        }
        $this->newLine();

        $tz = Scheduler::timezone();
        $this->table(['Task', 'Schedule ('.$tz.')', 'State', 'Last run', 'Result', 'Next run'], array_map(fn ($r) => [
            $r['key'].($r['core'] ? '' : ' (client)'),
            $r['cron'],
            ! $r['configured'] ? 'off (config)' : ($r['skip'] ? 'idle: '.$r['skip'] : 'on'),
            $r['last'] ? $r['last']['at']->copy()->setTimezone($tz)->format('Y-m-d H:i').' ('.$r['last']['via'].')' : 'never',
            $r['last'] ? $r['last']['status'].': '.$r['last']['summary'] : '',
            $r['next']?->copy()->setTimezone($tz)->format('Y-m-d H:i') ?? '–',
        ], $rows));

        return self::SUCCESS;
    }
}
