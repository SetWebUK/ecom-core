<?php

namespace Pine\Commerce\Scheduling;

use Illuminate\Console\Scheduling\ManagesFrequencies;
use Illuminate\Support\Facades\Artisan;
use InvalidArgumentException;
use Pine\Commerce\Support\Features;
use RuntimeException;

/**
 * A scheduled task registered by client code with Commerce::scheduledTask() (docs/EXTENDING.md "Scheduled tasks").
 * It runs exactly like a core task: Laravel's scheduler (one cron line), the web fallback without cron,
 * `commerce:schedule:task`, a recorded last result per key and a row in `commerce:schedule:status` /
 * Settings › Scheduled tasks.
 *
 * What it runs (exactly one of):
 *  - 'task'    => a class extending Scheduling\Task (resolved from the container on every run; its skipReason() applies);
 *  - 'call'    => any callable (called through the container, so it may type-hint dependencies). A returned string is the
 *                 one-line summary;
 *  - 'command' => an artisan command line ('erp:sync --quiet'). A non-zero exit code counts as failed.
 *
 * Guards (all optional; any one that fails = "skipped" with the reason): 'feature' (a commerce feature switch),
 * 'setting' (an owner setting that must be truthy; 'setting_default' when it was never saved) and 'skip'
 * (callable returning a reason or null).
 */
class ClientTask extends Task
{
    /** Frequency methods (Laravel's ManagesFrequencies) that never make sense here: sub-minute, time windows, tz. */
    protected const NOT_A_FREQUENCY = ['cron', 'between', 'unlessBetween', 'timezone', 'everySecond', 'everyTwoSeconds',
        'everyFiveSeconds', 'everyTenSeconds', 'everyFifteenSeconds', 'everyTwentySeconds', 'everyThirtySeconds', 'repeatEvery'];

    /** @param  array{key:string, label:string, description:string, cron:string, task?:class-string<Task>|null, call?:callable|null, command?:string|null, feature?:?string, setting?:?string, setting_default?:mixed, skip?:?callable}  $definition */
    public function __construct(public readonly array $definition) {}

    /**
     * Validate and normalise a Commerce::scheduledTask() registration.
     *
     * @return array{key:string, label:string, description:string, cron:string, task:?string, call:?callable, command:?string, feature:?string, setting:?string, setting_default:mixed, skip:?callable}
     */
    public static function define(string $key, array $options): array
    {
        if (! preg_match('/^[a-z0-9][a-z0-9._-]*$/', $key)) {
            throw new InvalidArgumentException("Scheduled task key [{$key}] may only contain a-z, 0-9, '.', '_' and '-'.");
        }
        if (isset(Scheduler::TASKS[$key])) {
            throw new InvalidArgumentException("Scheduled task key [{$key}] is a core task; choose your own key (e.g. 'client.{$key}').");
        }
        $runs = array_filter(['task' => $options['task'] ?? null, 'call' => $options['call'] ?? null, 'command' => $options['command'] ?? null], fn ($v) => $v !== null && $v !== '');
        if (count($runs) !== 1) {
            throw new InvalidArgumentException("Scheduled task [{$key}] needs exactly one of 'task' (a Task class), 'call' (a callable) or 'command' (an artisan command).");
        }
        if (isset($runs['task']) && ! (is_string($runs['task']) && is_a($runs['task'], Task::class, true))) {
            throw new InvalidArgumentException("Scheduled task [{$key}]: 'task' must be a class extending ".Task::class.'.');
        }
        if (isset($runs['call']) && ! is_callable($runs['call']) && ! (is_string($runs['call']) && class_exists($runs['call']))) {
            throw new InvalidArgumentException("Scheduled task [{$key}]: 'call' must be callable.");
        }
        if (isset($runs['command']) && ! is_string($runs['command'])) {
            throw new InvalidArgumentException("Scheduled task [{$key}]: 'command' must be an artisan command line.");
        }
        if (isset($options['skip']) && ! is_callable($options['skip'])) {
            throw new InvalidArgumentException("Scheduled task [{$key}]: 'skip' must be callable.");
        }

        return [
            'key' => $key,
            'label' => (string) ($options['label'] ?? $key),
            'description' => (string) ($options['description'] ?? (isset($runs['command']) ? 'Runs `php artisan '.$runs['command'].'`.' : '')),
            'cron' => static::cronFor($options['schedule'] ?? null, $key),
            'task' => $runs['task'] ?? null,
            'call' => $runs['call'] ?? null,
            'command' => $runs['command'] ?? null,
            'feature' => isset($options['feature']) ? (string) $options['feature'] : null,
            'setting' => isset($options['setting']) ? (string) $options['setting'] : null,
            'setting_default' => $options['setting_default'] ?? false,
            'skip' => $options['skip'] ?? null,
        ];
    }

    /**
     * A schedule as a cron expression: a cron expression ('*\/15 * * * *'), a Laravel frequency method ('hourly',
     * 'everyFifteenMinutes'), a method with arguments ('dailyAt:02:30', 'weeklyOn:1,08:00') or several chained with '|'
     * ('weekdays|dailyAt:09:00'). The array form ['dailyAt', '02:30'] is one method with its arguments.
     */
    public static function cronFor(string|array|null $schedule, string $key = ''): string
    {
        if ($schedule === null || $schedule === '' || $schedule === []) {
            throw new InvalidArgumentException("Scheduled task [{$key}] needs a 'schedule' (cron expression or frequency method, e.g. 'hourly').");
        }
        if (is_string($schedule) && preg_match('/^\S+(\s+\S+){4}$/', trim($schedule))) {
            $schedule = trim($schedule);
            if (! \Cron\CronExpression::isValidExpression($schedule)) {
                throw new InvalidArgumentException("Scheduled task [{$key}]: [{$schedule}] is not a valid cron expression.");
            }

            return $schedule;
        }

        $calls = is_array($schedule)
            ? [[(string) array_shift($schedule), array_values($schedule)]]
            : array_map(function (string $part) {
                [$method, $args] = array_pad(explode(':', trim($part), 2), 2, null);

                // dailyAt:02:30 – one time argument; weeklyOn:1,08:00 – comma-separated arguments
                return [$method, $args === null || $args === '' ? [] : explode(',', $args)];
            }, explode('|', $schedule));

        $builder = new class
        {
            use ManagesFrequencies;

            public $expression = '* * * * *';

            public $timezone;

            public $repeatSeconds;
        };
        foreach ($calls as [$method, $args]) {
            if ($method === '' || in_array($method, self::NOT_A_FREQUENCY, true) || ! method_exists($builder, $method)
                || ! (new \ReflectionMethod($builder, $method))->isPublic()) {
                throw new InvalidArgumentException("Scheduled task [{$key}]: [{$method}] is not a schedule frequency (use a cron expression or a method such as hourly, dailyAt:02:30, everyFifteenMinutes).");
            }
            try {
                $builder->{$method}(...array_map(fn ($a) => is_numeric($a) ? (int) $a : $a, $args));
            } catch (\Throwable $e) {
                throw new InvalidArgumentException("Scheduled task [{$key}]: frequency [{$method}] failed: ".$e->getMessage());
            }
        }
        if (! \Cron\CronExpression::isValidExpression($builder->expression)) {
            throw new InvalidArgumentException("Scheduled task [{$key}]: the schedule gives an invalid cron expression [{$builder->expression}].");
        }

        return $builder->expression;
    }

    public function skipReason(): ?string
    {
        $d = $this->definition;
        if ($d['feature'] && ! Features::enabled($d['feature'], false)) {
            return "Feature “{$d['feature']}” is switched off.";
        }
        if ($d['setting'] && ! filter_var(setting($d['setting'], $d['setting_default'] ?? false), FILTER_VALIDATE_BOOL)) {
            return "Switched off in Settings ({$d['setting']}).";
        }
        if ($d['skip'] && ($reason = app()->call($d['skip']))) {
            return (string) $reason;
        }
        if ($d['task']) {
            return app($d['task'])->skipReason();
        }

        return null;
    }

    public function handle(): string
    {
        $d = $this->definition;
        if ($d['task']) {
            return app($d['task'])->handle();
        }
        if ($d['command']) {
            $exit = Artisan::call($d['command']);
            $output = trim(Artisan::output());
            $last = $output === '' ? '' : trim((string) last(preg_split('/\R/', $output)));
            if ($exit !== 0) {
                throw new RuntimeException("`{$d['command']}` exited with code {$exit}".($last !== '' ? ": {$last}" : '.'));
            }

            return $last !== '' ? mb_substr($last, 0, 250) : "`{$d['command']}` finished";
        }

        $call = $d['call'];
        $result = is_string($call) && ! is_callable($call) ? app()->call([app($call), '__invoke']) : app()->call($call);

        return is_string($result) && $result !== '' ? $result : 'Done';
    }
}
