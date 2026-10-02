<?php

namespace Pine\Commerce\Tests\Updater;

use Illuminate\Support\Facades\Hash;
use Pine\Commerce\Commerce;
use Pine\Commerce\Models\PlatformUpdate;
use Pine\Commerce\Tests\Concerns\FakesUpdaterProject;
use Pine\Commerce\Tests\Concerns\InstallsNeutralStore;
use Pine\Commerce\Tests\TestCase;

/**
 * Admin › Updates screens: administrators only, Check now, approval with password + confirmation, background
 * launch, the status endpoint, the sidebar badge and the dashboard notice.
 */
class UpdatesAdminTest extends TestCase
{
    use FakesUpdaterProject;
    use InstallsNeutralStore;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpNeutralStore();
        $this->setUpUpdaterProject();
        $this->fakeGithub();
    }

    protected function tearDown(): void
    {
        $this->tearDownUpdaterProject();
        $this->tearDownNeutralStore();
        parent::tearDown();
    }

    protected function manager(): \Pine\Commerce\Models\User
    {
        return Commerce::userModel()::forceCreate(['name' => 'Mia Manager', 'first_name' => 'Mia', 'email' => 'mia@shop.example.test',
            'password' => Hash::make('Manager-pass-123'), 'role' => 'manager', 'is_active' => true]);
    }

    public function test_only_administrators_see_updates(): void
    {
        $this->get(route('admin.updates.index'))->assertRedirect(route('admin.login'));

        $this->actingAs($this->manager());
        $this->get(route('admin.updates.index'))->assertForbidden();
        $this->post(route('admin.updates.check'))->assertForbidden();
        $this->assertStringNotContainsString(route('admin.updates.index'), $this->get(route('admin.dashboard'))->assertOk()->getContent());

        $this->actingAs($this->neutralAdmin());
        $this->get(route('admin.updates.index'))->assertOk()->assertSee('Never – press “Check now”.', false);
        $this->assertStringContainsString('href="'.route('admin.updates.index').'"', $this->get(route('admin.dashboard'))->getContent());

        config(['commerce.features.updater' => false]);
        $this->get(route('admin.updates.index'))->assertNotFound();
        $this->assertStringNotContainsString(route('admin.updates.index'), $this->get(route('admin.dashboard'))->assertOk()->getContent());
    }

    public function test_check_now_shows_the_release_its_notes_and_the_client_actions(): void
    {
        $this->actingAs($this->neutralAdmin());
        $this->from(route('admin.updates.index'))->post(route('admin.updates.check'))
            ->assertRedirect(route('admin.updates.index'))->assertSessionHas('warning');
        $check = PlatformUpdate::latestCheck();
        $this->assertSame('owner@shop.example.test', $check->user_email);
        $this->assertSame('admin', $check->via);

        $html = $this->get(route('admin.updates.index'))->assertOk()
            ->assertSee('Version 1.3.0 is available')
            ->assertSee('Approve &amp; install 1.3.0', false)
            ->assertSee('Version 2.0.0 requires a developer')
            ->assertSee('Client actions required')
            ->assertSee('data-version="1.2.2"', false)
            ->getContent();
        $this->assertStringNotContainsString('<script>alert(1)</script>', $html, 'release notes are escaped');

        // sidebar badge + dashboard notice
        $dashboard = $this->get(route('admin.dashboard'))->assertOk();
        $dashboard->assertSee('Platform update available: pine/commerce 1.3.0')->assertSee('nav__count--alert" title="Platform update available"', false);
    }

    public function test_approval_needs_the_password_and_the_confirmation_then_starts_in_the_background(): void
    {
        $admin = $this->neutralAdmin();
        $this->actingAs($admin);
        $this->post(route('admin.updates.check'));
        $check = PlatformUpdate::latestCheck();

        $this->from(route('admin.updates.index'))->post(route('admin.updates.install'), ['check' => $check->id, 'password' => 'wrong', 'confirm' => '1'])
            ->assertRedirect(route('admin.updates.index'))->assertSessionHasErrors('password');
        $this->from(route('admin.updates.index'))->post(route('admin.updates.install'), ['check' => $check->id, 'password' => 'Correct-Horse-9'])
            ->assertSessionHasErrors('confirm');
        $this->assertSame(0, PlatformUpdate::query()->where('type', 'core')->count());
        $this->assertSame([], $this->runner->launched);

        $response = $this->post(route('admin.updates.install'), ['check' => $check->id, 'password' => 'Correct-Horse-9', 'confirm' => '1']);
        $update = PlatformUpdate::query()->where('type', 'core')->sole();
        $response->assertRedirect(route('admin.updates.show', $update))->assertSessionHas('success')->assertCookie('laravel_maintenance', null, false);
        $this->assertSame('approved', $update->status);
        $this->assertSame($admin->id, $update->user_id);
        $this->assertSame('1.3.0', $update->to_version);
        $this->assertCount(1, $this->runner->launched);
        $this->assertSame(['/usr/bin/php-test', $this->project.'/artisan', 'commerce:update:run', (string) $update->id], $this->runner->launched[0]['command']);
        $this->assertArrayHasKey('HOME', $this->runner->launched[0]['env']);
        $this->assertArrayHasKey('COMPOSER_HOME', $this->runner->launched[0]['env']);

        // a second approval while it waits is refused
        $this->post(route('admin.updates.install'), ['check' => $check->id, 'password' => 'Correct-Horse-9', 'confirm' => '1'])->assertSessionHas('error');

        // the run page + status endpoint (what the page polls)
        $this->get(route('admin.updates.show', $update))->assertOk()->assertSee('Maintenance bypass')->assertSee(url('/'.$update->meta('secret')));
        $update->forceFill(['status' => 'running', 'step' => 'composer', 'log' => "line 1\nline 2\nline 3\n"])->putMeta(['pid' => getmypid()])->save();
        $this->getJson(route('admin.updates.status', [$update, 'from' => 1]))->assertOk()
            ->assertJson(['status' => 'running', 'step' => 'composer', 'step_label' => 'composer update pine/commerce', 'lines' => ['line 2', 'line 3'], 'next' => 3, 'finished' => false]);

        $this->actingAs($this->manager());
        $this->getJson(route('admin.updates.status', $update))->assertForbidden();
        $this->post(route('admin.updates.install'), ['check' => $check->id, 'password' => 'Manager-pass-123', 'confirm' => '1'])->assertForbidden();
    }

    public function test_a_check_that_is_not_the_newest_cannot_be_approved_and_a_failed_launch_says_what_to_run(): void
    {
        $this->actingAs($this->neutralAdmin());
        $this->post(route('admin.updates.check'));
        $old = PlatformUpdate::latestCheck();
        $this->post(route('admin.updates.check'));
        $this->post(route('admin.updates.install'), ['check' => $old->id, 'password' => 'Correct-Horse-9', 'confirm' => '1'])
            ->assertSessionHas('error', 'That check is out of date – check for updates again.');

        $this->runner->launchError = new \RuntimeException('proc_open() is disabled');
        $this->post(route('admin.updates.install'), ['check' => PlatformUpdate::latestCheck()->id, 'password' => 'Correct-Horse-9', 'confirm' => '1'])
            ->assertSessionHas('warning', fn ($message) => str_contains($message, 'proc_open() is disabled') && str_contains($message, 'php artisan commerce:update:run'));
    }

    public function test_an_interrupted_run_is_marked_failed(): void
    {
        $this->actingAs($this->neutralAdmin());
        $update = PlatformUpdate::create(['type' => 'core', 'status' => 'running', 'from_version' => '1.2.1', 'to_version' => '1.3.0',
            'approved_at' => now(), 'meta' => ['pid' => 999999999]]);
        $this->getJson(route('admin.updates.status', $update))->assertOk()->assertJson(['status' => 'failed', 'finished' => true]);
        $this->assertStringContainsString('interrupted', (string) $update->fresh()->error);
    }
}
