<?php

namespace Pine\Commerce\Tests\Feature;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Cache;
use InvalidArgumentException;
use Pine\Commerce\Commerce;
use Pine\Commerce\Models\Setting;
use Pine\Commerce\Scheduling\ClientTask;
use Pine\Commerce\Scheduling\Scheduler;
use Pine\Commerce\Scheduling\Task;
use Pine\Commerce\Tests\Concerns\InstallsNeutralStore;
use Pine\Commerce\Tests\TestCase;

/**
 * Commerce::scheduledTask(): client scheduled tasks run, record and report exactly like the core ones (Laravel's
 * scheduler, the web fallback, commerce:schedule:task / :status, Settings › Scheduled tasks), with schedule parsing
 * and feature / setting guards. Fresh package install on in-memory SQLite only.
 */
class ClientScheduledTaskTest extends TestCase
{
    use InstallsNeutralStore;

    public static int $calls = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpNeutralStore();
        static::$calls = 0;
    }

    protected function tearDown(): void
    {
        $this->tearDownNeutralStore();
        parent::tearDown();
    }

    public function test_schedules_accept_cron_expressions_and_frequency_methods(): void
    {
        $this->assertSame('*/15 * * * *', ClientTask::cronFor('*/15 * * * *'));
        $this->assertSame('0 * * * *', ClientTask::cronFor('hourly'));
        $this->assertSame('*/15 * * * *', ClientTask::cronFor('everyFifteenMinutes'));
        $this->assertSame('30 2 * * *', ClientTask::cronFor('dailyAt:02:30'));
        $this->assertSame('30 2 * * *', ClientTask::cronFor(['dailyAt', '02:30']));
        $this->assertSame('0 8 * * 1', ClientTask::cronFor('weeklyOn:1,08:00'));
        $this->assertSame('0 9 * * 1-5', ClientTask::cronFor('weekdays|dailyAt:09:00'));

        foreach (['nonsense', 'everySecond', 'between:09:00,17:00', '61 * * * *', ''] as $bad) {
            try {
                ClientTask::cronFor($bad, 'x');
                $this->fail("[{$bad}] accepted");
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_registration_is_validated(): void
    {
        $bad = [
            ['Bad Key', ['schedule' => 'hourly', 'call' => fn () => 'x']],
            ['orders.cancel-unpaid', ['schedule' => 'hourly', 'call' => fn () => 'x']],        // core key
            ['client.none', ['schedule' => 'hourly']],                                        // nothing to run
            ['client.both', ['schedule' => 'hourly', 'call' => fn () => 'x', 'command' => 'about']],
            ['client.class', ['schedule' => 'hourly', 'task' => \stdClass::class]],
            ['client.when', ['call' => fn () => 'x']],                                        // no schedule
        ];
        foreach ($bad as [$key, $options]) {
            try {
                Commerce::scheduledTask($key, $options);
                $this->fail("[{$key}] accepted");
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
        $this->assertSame([], Commerce::registry()->scheduledTasks());
    }

    public function test_a_client_task_is_scheduled_run_recorded_and_listed(): void
    {
        Commerce::scheduledTask('client.erp-sync', [
            'label' => 'ERP stock sync', 'description' => 'Pulls stock levels from the ERP.',
            'schedule' => 'everyFifteenMinutes',
            'call' => function (\Illuminate\Contracts\Config\Repository $config) {
                static::$calls++;

                return '3 products updated';
            },
        ]);

        $this->assertTrue(Scheduler::has('client.erp-sync'));
        $this->assertTrue(Scheduler::configured('client.erp-sync'));
        $this->assertFalse(Scheduler::tasks()['client.erp-sync']['core']);

        $schedule = new Schedule;
        Scheduler::register($schedule);
        $events = collect($schedule->events())->keyBy('description');
        $this->assertCount(count(Scheduler::TASKS) + 2, $schedule->events(), 'heartbeat + core + client');
        $this->assertSame('*/15 * * * *', $events['ERP stock sync']->expression);

        $result = Scheduler::run('client.erp-sync', 'cron');
        $this->assertSame(['status' => 'ok', 'summary' => '3 products updated'], array_intersect_key($result, ['status' => 1, 'summary' => 1]));
        $this->assertSame(1, static::$calls);
        $last = Scheduler::lastRun('client.erp-sync');
        $this->assertSame('cron', $last['via']);
        $this->assertSame('scheduler', Setting::query()->where('key', 'scheduler.task.client.erp-sync')->value('group'));

        $row = collect(Scheduler::status())->firstWhere('key', 'client.erp-sync');
        $this->assertSame('ERP stock sync', $row['label']);
        $this->assertNull($row['skip']);
        $this->assertNotNull($row['next']);

        $this->artisan('commerce:schedule:status')->expectsOutputToContain('client.erp-sync (client)')->assertSuccessful();
        $this->artisan('commerce:schedule:status', ['--json' => true])->expectsOutputToContain('"source": "client"')->assertSuccessful();
        $this->artisan('commerce:schedule:task', ['task' => 'client.erp-sync'])->expectsOutputToContain('client.erp-sync: ok – 3 products updated')->assertSuccessful();
        $this->assertSame(2, static::$calls);
        $this->assertSame('manual', Scheduler::lastRun('client.erp-sync')['via']);

        $this->actingAs($this->neutralAdmin());
        $this->get(route('admin.settings.edit', 'automation'))->assertOk()
            ->assertSee('data-task="client.erp-sync"', false)
            ->assertSee('ERP stock sync')
            ->assertSee('3 products updated');
    }

    public function test_the_web_fallback_runs_due_client_tasks(): void
    {
        Commerce::scheduledTask('client.nightly', ['schedule' => 'dailyAt:02:00', 'call' => function () {
            static::$calls++;
        }]);
        $ran = Scheduler::webFallback();
        $this->assertArrayHasKey('client.nightly', $ran, 'never ran = due');
        $this->assertSame('Done', $ran['client.nightly']['summary'], 'no string returned');
        $this->assertSame('web', Scheduler::lastRun('client.nightly')['via']);

        Cache::forget('commerce.scheduler.web-fallback');
        $this->travel(6)->minutes();
        $this->assertArrayNotHasKey('client.nightly', Scheduler::webFallback(), 'daily – not due again');
        $this->assertSame(1, static::$calls);
    }

    public function test_config_feature_and_setting_guards(): void
    {
        Commerce::scheduledTask('client.reviews-digest', ['schedule' => 'daily', 'feature' => 'reviews', 'setting' => 'client.digest_enabled',
            'call' => function () {
                static::$calls++;

                return 'sent';
            }]);

        $result = Scheduler::run('client.reviews-digest');
        $this->assertSame('skipped', $result['status']);
        $this->assertStringContainsString('client.digest_enabled', $result['summary']);

        Setting::set('client.digest_enabled', true);
        config(['commerce.features.reviews' => false]);
        $this->assertStringContainsString('reviews', Scheduler::run('client.reviews-digest')['summary']);
        $this->assertSame(0, static::$calls);

        config(['commerce.features.reviews' => true]);
        $this->assertSame('ok', Scheduler::run('client.reviews-digest')['status']);
        $this->assertSame(1, static::$calls);

        config(['commerce.scheduler.tasks' => ['client.reviews-digest' => false] + config('commerce.scheduler.tasks')]);
        $this->assertFalse(Scheduler::configured('client.reviews-digest'));
        $schedule = new Schedule;
        Scheduler::register($schedule);
        $this->assertCount(count(Scheduler::TASKS) + 1, $schedule->events(), 'switched off in config: not scheduled');
        $this->assertStringContainsString('Switched off in config', collect(Scheduler::status())->firstWhere('key', 'client.reviews-digest')['skip']);

        Commerce::scheduledTask('client.custom-skip', ['schedule' => 'hourly', 'skip' => fn () => 'Nothing to sync', 'call' => fn () => 'x']);
        $this->assertSame(['skipped', 'Nothing to sync'], array_values(array_intersect_key(Scheduler::run('client.custom-skip'), ['status' => 1, 'summary' => 1])));
    }

    public function test_task_classes_and_artisan_commands(): void
    {
        Commerce::scheduledTask('client.task-class', ['schedule' => 'hourly', 'task' => ClientScheduledTaskFixture::class]);
        $this->assertSame('skipped', Scheduler::run('client.task-class')['status'], 'the Task class skipReason() applies');
        ClientScheduledTaskFixture::$ready = true;
        $this->assertSame(['ok', 'fixture ran'], array_values(array_intersect_key(Scheduler::run('client.task-class'), ['status' => 1, 'summary' => 1])));
        ClientScheduledTaskFixture::$ready = false;

        $kernel = $this->app[\Illuminate\Contracts\Console\Kernel::class];
        $kernel->registerCommand(new class extends \Illuminate\Console\Command
        {
            protected $signature = 'client:ping';

            public function handle(): int
            {
                $this->info('pong 42');

                return 0;
            }
        });
        $kernel->registerCommand(new class extends \Illuminate\Console\Command
        {
            protected $signature = 'client:broken';

            public function handle(): int
            {
                $this->error('ERP unreachable');

                return 3;
            }
        });
        Commerce::scheduledTask('client.ping', ['schedule' => '*/5 * * * *', 'command' => 'client:ping']);
        Commerce::scheduledTask('client.broken', ['schedule' => 'hourly', 'command' => 'client:broken']);

        $this->assertSame(['ok', 'pong 42'], array_values(array_intersect_key(Scheduler::run('client.ping'), ['status' => 1, 'summary' => 1])));
        $this->assertStringContainsString('client:ping', Scheduler::tasks()['client.ping']['description']);
        $failed = Scheduler::run('client.broken');
        $this->assertSame('failed', $failed['status']);
        $this->assertStringContainsString('exited with code 3', $failed['summary']);
        $this->assertSame('failed', Scheduler::lastRun('client.broken')['status']);
    }

    public function test_a_throwing_client_task_is_recorded_and_never_throws(): void
    {
        Commerce::scheduledTask('client.flaky', ['schedule' => 'hourly', 'call' => fn () => throw new \RuntimeException('ERP timeout')]);
        $result = Scheduler::run('client.flaky');
        $this->assertSame('failed', $result['status']);
        $this->assertSame('ERP timeout', Scheduler::lastRun('client.flaky')['summary']);
    }
}

class ClientScheduledTaskFixture extends Task
{
    public static bool $ready = false;

    public function skipReason(): ?string
    {
        return static::$ready ? null : 'Not ready';
    }

    public function handle(): string
    {
        return 'fixture ran';
    }
}
