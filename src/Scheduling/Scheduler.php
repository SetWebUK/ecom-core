<?php

namespace Pine\Commerce\Scheduling;

use Cron\CronExpression;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * The core's scheduled tasks (ARCHITECTURE §13.2) – registered with Laravel's scheduler by CommerceServiceProvider, so
 * one cron line runs them all:
 *
 *     * * * * * cd /path/to/app && php artisan schedule:run >> /dev/null 2>&1
 *
 * Nothing depends on cron. Every run of `schedule:run` writes a heartbeat (setting "scheduler.last_run"); while no
 * heartbeat is fresh the shop degrades gracefully:
 *   - unpaid card/PayPal orders are still cancelled when the checkout is visited (CheckoutService::cancelStaleOrders());
 *   - with config commerce.scheduler.web_fallback on, due tasks run after a storefront response (at most every
 *     5 minutes) – the package default for new clients.
 *
 * Client projects add their own tasks with Commerce::scheduledTask() (Scheduling\ClientTask); they run, record and
 * show up exactly like the core ones (tasks()).
 *
 * Config commerce.scheduler: 'enabled' (register anything at all), 'tasks' (key => on/off, core and client keys),
 * 'web_fallback', 'heartbeat_minutes'. Owner-facing on/off switches of individual tasks are settings (Settings › Scheduled tasks /
 * Abandoned carts) checked by each Task::skipReason(). Last run per task: setting "scheduler.task.{key}" (JSON).
 */
class Scheduler
{
    public const HEARTBEAT = 'scheduler.last_run';

    /** Web fallback runs at most once per this many seconds (and never while cron is running). */
    public const WEB_FALLBACK_SECONDS = 300;

    /**
     * key => [label, what it does, cron expression (store timezone), Task class].
     *
     * @var array<string, array{0:string, 1:string, 2:string, 3:class-string<Task>}>
     */
    public const TASKS = [
        'orders.cancel-unpaid' => ['Cancel unpaid orders',
            'Card/PayPal orders still unpaid after Settings › Checkout & orders › “Hold stock” minutes are cancelled and their stock is released.',
            '*/5 * * * *', Tasks\CancelUnpaidOrders::class],
        'carts.abandoned-emails' => ['Abandoned-cart reminders',
            'Sends the reminder emails set up in Settings › Abandoned carts (only while they are switched on there).',
            '*/10 * * * *', Tasks\SendAbandonedCartEmails::class],
        'stock.back-in-stock' => ['Back-in-stock alerts',
            'Emails “tell me when it’s back” subscribers whose product can be bought again, whatever put it back in stock.',
            '20 * * * *', Tasks\SendBackInStockAlerts::class],
        'catalog.sale-prices' => ['Scheduled sale prices',
            'Updates a product’s stored price when its scheduled sale starts or ends, so sorting, filters and the feed follow.',
            '*/5 * * * *', Tasks\RefreshSalePrices::class],
        'maintenance.prune' => ['Tidy up old data',
            'Deletes guest baskets left longer than Settings › Scheduled tasks › “Keep abandoned guest baskets”, expired sessions and expired password-reset links.',
            '40 3 * * *', Tasks\PruneStaleData::class],
        'inventory.low-stock-email' => ['Daily low-stock email',
            'Emails the shop a list of products that are running low or sold out (Settings › Scheduled tasks, off by default).',
            '0 7 * * *', Tasks\SendLowStockReport::class],
    ];

    /**
     * Every task: the core ones (TASKS) then the client's (Commerce::scheduledTask), in registration order.
     *
     * @return array<string, array{label:string, description:string, cron:string, core:bool}>
     */
    public static function tasks(): array
    {
        $tasks = [];
        foreach (self::TASKS as $key => [$label, $description, $cron]) {
            $tasks[$key] = ['label' => $label, 'description' => $description, 'cron' => $cron, 'core' => true];
        }
        foreach (app(\Pine\Commerce\Extensions\ExtensionRegistry::class)->scheduledTasks() as $key => $definition) {
            $tasks[$key] = ['label' => $definition['label'], 'description' => $definition['description'], 'cron' => $definition['cron'], 'core' => false];
        }

        return $tasks;
    }

    /** Is $key a core or client task? */
    public static function has(string $key): bool
    {
        return isset(static::tasks()[$key]);
    }

    /** The Task instance that runs $key (core: resolved from the container; client: a ClientTask). */
    public static function task(string $key): Task
    {
        if (isset(self::TASKS[$key])) {
            return app(self::TASKS[$key][3]);
        }
        $definition = app(\Pine\Commerce\Extensions\ExtensionRegistry::class)->scheduledTasks()[$key] ?? null;
        if ($definition === null) {
            throw new \InvalidArgumentException("Unknown scheduled task [{$key}].");
        }

        return new ClientTask($definition);
    }

    /** Is the task switched on in config (commerce.scheduler.enabled + commerce.scheduler.tasks.{key})? */
    public static function configured(string $key): bool
    {
        if (! static::has($key) || ! filter_var(config('commerce.scheduler.enabled', true), FILTER_VALIDATE_BOOL)) {
            return false;
        }

        // task keys contain dots, so no dot-notation lookup
        return filter_var(((array) config('commerce.scheduler.tasks', []))[$key] ?? true, FILTER_VALIDATE_BOOL);
    }

    public static function timezone(): string
    {
        return (string) (config('commerce.store.timezone') ?: config('app.timezone', 'UTC'));
    }

    /** Add the heartbeat and every configured task to Laravel's schedule (CommerceServiceProvider). */
    public static function register(Schedule $schedule): void
    {
        if (! filter_var(config('commerce.scheduler.enabled', true), FILTER_VALIDATE_BOOL)) {
            return;
        }
        $schedule->call(fn () => static::beat())->everyMinute()->name('commerce:heartbeat');

        foreach (static::tasks() as $key => ['label' => $label, 'cron' => $cron]) {
            if (! static::configured($key)) {
                continue;
            }
            $schedule->call(fn () => static::run($key, 'cron'))
                ->cron($cron)
                ->timezone(static::timezone())
                ->name('commerce:'.$key)
                ->description($label)
                ->withoutOverlapping(60);
        }
    }

    // ------------------------------------------------------------------ running

    /**
     * Run one task now and record the result. Never throws.
     *
     * @return array{status:string, summary:string, ms:int}  status = ok | skipped | failed
     */
    public static function run(string $key, string $via = 'manual'): array
    {
        $started = microtime(true);
        try {
            $task = static::task($key);
            if ($reason = $task->skipReason()) {
                $result = ['status' => 'skipped', 'summary' => $reason];
            } else {
                $result = ['status' => 'ok', 'summary' => $task->handle()];
            }
        } catch (Throwable $e) {
            Log::warning("Scheduled task {$key} failed: ".$e->getMessage());
            $result = ['status' => 'failed', 'summary' => mb_substr($e->getMessage(), 0, 250)];
        }
        $result['ms'] = (int) round((microtime(true) - $started) * 1000);
        static::record($key, $result + ['via' => $via]);

        return $result;
    }

    /**
     * No cron: run every configured task that is due since its last run, after a storefront response
     * (CommerceServiceProvider registers this as a terminating callback on web requests). At most once per
     * WEB_FALLBACK_SECONDS; does nothing while config commerce.scheduler.web_fallback is off or cron is running.
     *
     * @return array<string, array{status:string, summary:string, ms:int}> the tasks that ran
     */
    public static function webFallback(): array
    {
        if (! filter_var(config('commerce.scheduler.enabled', true), FILTER_VALIDATE_BOOL)
            || ! filter_var(config('commerce.scheduler.web_fallback', false), FILTER_VALIDATE_BOOL)) {
            return [];
        }
        try {
            if (! Cache::add('commerce.scheduler.web-fallback', 1, self::WEB_FALLBACK_SECONDS) || static::cronRunning()) {
                return [];
            }
            $ran = [];
            foreach (array_keys(static::tasks()) as $key) {
                if (static::configured($key) && static::isDue($key)) {
                    $ran[$key] = static::run($key, 'web');
                }
            }

            return $ran;
        } catch (Throwable $e) {
            Log::warning('Scheduler web fallback failed: '.$e->getMessage());

            return [];
        }
    }

    /** Due since its last run (a task that never ran is due). */
    public static function isDue(string $key, ?Carbon $now = null): bool
    {
        $last = static::lastRun($key);
        if (! $last) {
            return true;
        }
        $next = static::nextRun($key, $last['at']);

        return $next !== null && $next->lte($now ?? now());
    }

    // ------------------------------------------------------------------ heartbeat + state

    /** Written by every `schedule:run` (the commerce:heartbeat event). Not through Setting::set: no cache flush every minute. */
    public static function beat(): void
    {
        static::put(self::HEARTBEAT, now()->toIso8601String());
    }

    public static function lastHeartbeat(): ?Carbon
    {
        $value = static::get(self::HEARTBEAT);

        try {
            return $value ? Carbon::parse($value) : null;
        } catch (Throwable) {
            return null;
        }
    }

    /** Cron ran `schedule:run` within the last commerce.scheduler.heartbeat_minutes (default 5) minutes. */
    public static function cronRunning(): bool
    {
        $beat = static::lastHeartbeat();

        return $beat !== null && $beat->gte(now()->subMinutes(max(1, (int) config('commerce.scheduler.heartbeat_minutes', 5))));
    }

    /** @return array{at:Carbon, status:string, summary:string, ms:int, via:string}|null */
    public static function lastRun(string $key): ?array
    {
        $data = json_decode((string) static::get('scheduler.task.'.$key), true);
        if (! is_array($data) || empty($data['at'])) {
            return null;
        }
        try {
            $data['at'] = Carbon::parse($data['at']);
        } catch (Throwable) {
            return null;
        }

        return $data + ['status' => 'ok', 'summary' => '', 'ms' => 0, 'via' => 'cron'];
    }

    /** Next time cron would run the task after $from (default now), in the app timezone. */
    public static function nextRun(string $key, ?Carbon $from = null): ?Carbon
    {
        $cron = static::tasks()[$key]['cron'] ?? null;
        if ($cron === null) {
            return null;
        }
        try {
            $from = ($from ?? now())->copy()->setTimezone(static::timezone());
            $next = (new CronExpression($cron))->getNextRunDate($from->toDateTime(), 0, false, static::timezone());

            return Carbon::instance($next)->setTimezone(config('app.timezone', 'UTC'));
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Every task with its config switch, schedule, last and next run (commerce:schedule:status, Settings › Scheduled tasks).
     *
     * @return list<array{key:string, label:string, description:string, cron:string, core:bool, configured:bool, skip:?string, last:?array, next:?Carbon}>
     */
    public static function status(): array
    {
        $rows = [];
        foreach (static::tasks() as $key => ['label' => $label, 'description' => $description, 'cron' => $cron, 'core' => $core]) {
            $skip = null;
            try {
                $skip = static::task($key)->skipReason();
            } catch (Throwable $e) {
                $skip = 'Cannot check: '.$e->getMessage();
            }
            $configured = static::configured($key);
            $rows[] = [
                'key' => $key, 'label' => $label, 'description' => $description, 'cron' => $cron, 'core' => $core,
                'configured' => $configured, 'skip' => $configured ? $skip : 'Switched off in config/commerce.php (scheduler.tasks).',
                'last' => static::lastRun($key), 'next' => $configured ? static::nextRun($key) : null,
            ];
        }

        return $rows;
    }

    /** Record a task result (setting "scheduler.task.{key}"). */
    public static function record(string $key, array $result): void
    {
        static::put('scheduler.task.'.$key, json_encode(['at' => now()->toIso8601String()] + $result));
    }

    /** Raw read of a scheduler setting, bypassing the settings cache (these rows change every minute). */
    protected static function get(string $key): ?string
    {
        try {
            $value = DB::table('settings')->where('key', $key)->value('value');

            return $value === null ? null : (string) $value;
        } catch (Throwable) {
            return null;
        }
    }

    protected static function put(string $key, string $value): void
    {
        try {
            $now = now();
            $updated = DB::table('settings')->where('key', $key)->update(['value' => $value, 'updated_at' => $now]);
            if (! $updated) {
                DB::table('settings')->insertOrIgnore(['group' => 'scheduler', 'key' => $key, 'value' => $value, 'created_at' => $now, 'updated_at' => $now]);
            }
        } catch (Throwable $e) {
            Log::warning('Scheduler state not saved ('.$key.'): '.$e->getMessage());
        }
    }
}
