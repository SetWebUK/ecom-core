<?php

namespace Pine\Commerce\Tests\Updater;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Pine\Commerce\Models\PlatformUpdate;
use Pine\Commerce\Tests\Concerns\FakesUpdaterProject;
use Pine\Commerce\Tests\TestCase;
use Pine\Commerce\Updater\ProcessResult;
use Pine\Commerce\Updater\UpdateChecker;
use Pine\Commerce\Updater\UpdateLock;
use Pine\Commerce\Updater\UpdateManager;
use Pine\Commerce\Updater\UpdateRunner;

/**
 * Admin › Updates: the approved-update runner with a fake process runner (no composer, artisan or mysqldump runs):
 * approval is required, the step order, one run at a time, and the rollback when a step fails.
 */
class UpdateRunnerTest extends TestCase
{
    use FakesUpdaterProject;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpUpdaterProject();
        $this->fakeGithub();
    }

    protected function tearDown(): void
    {
        $this->tearDownUpdaterProject();
        parent::tearDown();
    }

    protected function approved(): PlatformUpdate
    {
        $check = app(UpdateChecker::class)->check(null, 'cli');
        $this->assertSame('1.3.0', $check->result['latest']);

        return app(UpdateManager::class)->approve($check, null, 'cli');
    }

    public function test_the_check_finds_the_installable_release_and_the_major_that_needs_a_developer(): void
    {
        $check = app(UpdateChecker::class)->check(null, 'schedule');
        $result = $check->result;
        $this->assertSame('checked', $check->status);
        $this->assertSame('1.2.1', $result['installed']);
        $this->assertSame('^1.2', $result['constraint']);
        $this->assertSame('GitHub API', $result['via']);
        $this->assertTrue($result['update_available']);
        $this->assertSame('1.3.0', $result['latest']);
        $this->assertSame('2.0.0', $result['blocked']['version']);
        $this->assertSame(['1.3.0', '1.2.2'], array_column($result['changelog'], 'version'), 'notes up to the installable release');
        $this->assertTrue($result['actions_required']);
        $this->assertSame('1.3.0', UpdateChecker::status()['latest']);
        $this->assertTrue(UpdateChecker::status()['available']);

        $this->setInstalled('v1.3.0');
        $this->assertFalse(UpdateChecker::status()['available'], 'an older check does not report the installed version as new');
    }

    public function test_nothing_runs_without_an_approval(): void
    {
        $check = app(UpdateChecker::class)->check();
        $this->artisan('commerce:update:run', ['id' => $check->id])->assertFailed();
        $this->artisan('commerce:update:run', ['id' => 999])->assertFailed();
        $this->artisan('commerce:update:run')->expectsOutputToContain('must be approved')->assertFailed();
        $this->artisan('commerce:update:run', ['--approve' => true, '--no-interaction' => true])->expectsOutputToContain('Not approved')->assertFailed();
        $this->assertSame(0, PlatformUpdate::query()->where('type', 'core')->count());
        $this->assertNull($this->runner->indexOf('composer-test update'));

        $this->expectException(\RuntimeException::class);
        app(UpdateRunner::class)->run($check);
    }

    public function test_an_approved_update_runs_every_step_in_order(): void
    {
        $this->runner->on('artisan optimize --no-ansi', fn () => "\e[32;1mDONE\e[39;22m caching");
        $update = $this->approved();
        $secret = $update->meta('secret');
        $this->assertSame('approved', $update->status);
        $this->assertSame('1.2.1', $update->from_version);
        $this->assertSame(32, strlen($secret));

        $ok = app(UpdateRunner::class)->run($update);
        $this->assertTrue($ok, (string) $update->fresh()->log);
        $update->refresh();

        $this->assertSame('succeeded', $update->status, (string) $update->log);
        $this->assertSame('{"lock": "updated"}', File::get($this->project.'/composer.lock'));
        $order = array_map(fn ($needle) => $this->runner->indexOf($needle), [
            'composer-test --version', 'artisan --version', 'commerce:doctor', 'artisan down --secret=', 'composer-test update pine/commerce --with-dependencies --with=pine/commerce:1.3.0',
            'artisan migrate --force', 'artisan commerce:publish', 'artisan commerce:theme:publish', 'artisan optimize:clear', 'artisan optimize --no-ansi', 'artisan up',
        ]);
        $this->assertNotContains(null, $order, implode("\n", $this->runner->commands));
        $sorted = $order;
        sort($sorted);
        $this->assertSame($sorted, $order, 'steps run in order');
        $this->assertNull($this->runner->indexOf('composer-test install'), 'no rollback');
        $this->assertStringNotContainsString($secret, (string) $update->log, 'the bypass secret never reaches the log');
        $this->assertStringContainsString('Updated to pine/commerce 1.3.0', (string) $update->log);
        $this->assertStringContainsString('DONE caching', (string) $update->log);
        $this->assertStringNotContainsString("\e[", (string) $update->log, 'terminal colours are stripped');
        $this->assertNull($update->meta('secret'), 'the bypass secret is forgotten when the run ends');
        $this->assertFileExists($this->project.'/storage/updater/runs/'.$update->id.'/composer.lock');
        $this->assertNotNull($update->started_at);
        $this->assertNotNull($update->finished_at);

        // an update runs once
        $this->artisan('commerce:update:run', ['id' => $update->id])->assertFailed();
    }

    public function test_a_failing_composer_update_restores_the_previous_version_and_brings_the_site_up(): void
    {
        $this->runner->on('composer-test update', function () {
            File::put($this->project.'/composer.lock', '{"lock": "half-written"}');

            return new ProcessResult(2, "Your requirements could not be resolved");
        });
        $update = $this->approved();

        $this->assertFalse(app(UpdateRunner::class)->run($update));
        $update->refresh();

        $this->assertSame('failed', $update->status);
        $this->assertSame('composer', $update->meta('failed_step'));
        $this->assertStringContainsString('could not be resolved', (string) $update->error);
        $this->assertSame('{"lock": "v1.2.1"}', File::get($this->project.'/composer.lock'), 'composer.lock restored');
        $this->assertSame('1.2.1', app(\Pine\Commerce\Updater\ComposerProject::class)->installedVersion());
        $install = $this->runner->indexOf('composer-test install');
        $this->assertNotNull($install);
        $this->assertGreaterThan($install, $this->runner->indexOf('artisan commerce:publish'), 'assets published again from the restored code');
        $this->assertNotNull($this->runner->indexOf('artisan up'));
        $this->assertNull($this->runner->indexOf('artisan migrate'), 'nothing after the failing step');
        $this->assertStringContainsString('Rolling back', (string) $update->log);
        $this->assertStringNotContainsString('DATABASE', (string) $update->log, 'no migration ran');
    }

    public function test_a_failing_migration_rolls_the_code_back_and_explains_the_database_restore(): void
    {
        $this->runner->on('artisan migrate', fn () => new ProcessResult(1, 'SQLSTATE[42S01]: table exists'));
        $update = $this->approved();
        $update->putMeta(['backup' => '/srv/backups/db-20261002-before-1.3.0.sql.gz'])->save();

        $this->assertFalse(app(UpdateRunner::class)->run($update));
        $update->refresh();
        $this->assertSame('failed', $update->status);
        $this->assertSame('migrate', $update->meta('failed_step'));
        $this->assertSame('{"lock": "v1.2.1"}', File::get($this->project.'/composer.lock'));
        $this->assertStringContainsString('database was NOT restored', (string) $update->log);
        $this->assertStringContainsString('gunzip -c /srv/backups/db-20261002-before-1.3.0.sql.gz | mysql', (string) $update->log);
        $this->assertNotNull($this->runner->indexOf('artisan up'));
    }

    public function test_a_new_failing_health_check_rolls_back(): void
    {
        $calls = 0;
        $this->runner->on('commerce:doctor', function () use (&$calls) {
            $calls++;

            $checks = [['title' => 'Old problem', 'status' => 'fail']];
            if ($calls > 1) {
                $checks[] = ['title' => 'Theme', 'status' => 'fail'];
            }

            return json_encode(['checks' => $checks]);
        });
        $update = $this->approved();
        $this->assertFalse(app(UpdateRunner::class)->run($update));
        $update->refresh();
        $this->assertSame('health', $update->meta('failed_step'));
        $this->assertStringContainsString('Theme', (string) $update->error);
        $this->assertStringNotContainsString('Old problem', (string) $update->error, 'checks failing before the update do not count');
        $this->assertNotNull($this->runner->indexOf('composer-test install'));
    }

    public function test_only_one_update_runs_at_a_time(): void
    {
        $update = $this->approved();
        $other = new UpdateLock;
        $this->assertTrue($other->acquire());
        try {
            $this->assertTrue(app(UpdateLock::class)->held());
            $this->assertFalse(app(UpdateRunner::class)->run($update));
            $update->refresh();
            $this->assertSame('failed', $update->status);
            $this->assertSame('Another update is running.', $update->error);
            $this->assertNull($this->runner->indexOf('composer-test'), 'nothing ran');
        } finally {
            $other->release();
        }
        $this->assertFalse(app(UpdateLock::class)->held());

        // and a second approval is refused while one is waiting
        $this->setInstalled('v1.2.1');
        $waiting = $this->approved();
        $this->expectExceptionMessage("Update #{$waiting->id} is already approved");
        app(UpdateManager::class)->approve(app(UpdateChecker::class)->check(), null);
    }

    public function test_cli_approval_installs_in_the_foreground(): void
    {
        $this->artisan('commerce:update:run', ['--approve' => true, '--yes' => true])
            ->expectsOutputToContain('Updated to pine/commerce 1.3.0')
            ->assertSuccessful();
        $update = PlatformUpdate::query()->where('type', 'core')->sole();
        $this->assertSame('succeeded', $update->status);
        $this->assertSame('cli', $update->via);
        $this->assertStringStartsWith('CLI', $update->user_email);

        $this->artisan('commerce:update:run', ['--approve' => true, '--yes' => true])->expectsOutputToContain('up to date')->assertSuccessful();
    }

    public function test_a_path_repository_is_never_updated(): void
    {
        File::put($this->project.'/composer.json', json_encode(['repositories' => [['type' => 'path', 'url' => '../ecom-core']],
            'require' => ['pine/commerce' => '*@dev']]));
        $check = app(UpdateChecker::class)->check();
        $this->assertSame('failed', $check->status);
        $this->assertStringContainsString('path repository', (string) $check->error);
    }
}
