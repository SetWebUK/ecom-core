<?php

namespace Pine\Commerce\Tests\Console;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Pine\Commerce\Console\ScratchDropCommand;
use Pine\Commerce\Tests\Concerns\UsesApplicationDatabase;
use Pine\Commerce\Tests\TestCase;

/**
 * commerce:install on the scratch connection (zz_t_install_* tables in the application database): fresh install,
 * idempotent re-run, and the real tables stay untouched. The zz_ tables are dropped afterwards.
 */
class InstallCommandTest extends TestCase
{
    use UsesApplicationDatabase;

    private const PREFIX = 'zz_t_install_';

    protected function setUp(): void
    {
        parent::setUp();
        $this->useScratchPrefix(self::PREFIX);
        ScratchDropCommand::drop('scratch');
    }

    protected function tearDown(): void
    {
        if (isset($this->app)) { // not booted when skipped (standalone package checkout)
            $this->useScratchPrefix(self::PREFIX);
            ScratchDropCommand::drop('scratch');
        }
        parent::tearDown();
    }

    public function test_fresh_install_on_the_scratch_connection_is_complete_and_idempotent(): void
    {
        $real = $this->realCounts();

        $this->artisan('commerce:install', [
            '--connection' => 'scratch', '--admin-email' => 'owner@example.test', '--admin-password' => 'Correct-Horse-9',
            '--store-name' => 'Acme Tools', '--order-start' => 5000, '--no-interaction' => true,
        ])->assertSuccessful();

        $db = DB::connection('scratch');
        $admin = $db->table('users')->where('email', 'owner@example.test')->first();
        $this->assertNotNull($admin);
        $this->assertSame('admin', $admin->role);
        $this->assertTrue(Hash::check('Correct-Horse-9', $admin->password));

        $settings = $db->table('settings')->pluck('value', 'key');
        $this->assertSame('Acme Tools', $settings['store.name']);
        $this->assertSame('5000', $settings['orders.starting_number']);
        $this->assertSame('owner@example.test', $settings['store.email']);

        $this->assertSame(['', 'about', 'contact', 'privacy-policy', 'terms-conditions'], $db->table('pages')->orderBy('path')->pluck('path')->all());
        $this->assertSame('home', $db->table('pages')->where('path', '')->value('template'));
        $this->assertSame(1, $db->table('shipping_methods')->where('cost', 0)->where('is_active', true)->count());
        $this->assertTrue($db->table('menus')->where('location', 'main')->exists());
        $this->assertTrue($db->table('menus')->where('location', 'footer_legal')->exists());
        $this->assertGreaterThan(0, $db->table('menu_items')->count());

        $snapshot = fn () => [
            $db->table('users')->count(), $db->table('settings')->count(), $db->table('pages')->count(),
            $db->table('menus')->count(), $db->table('menu_items')->count(), $db->table('shipping_methods')->count(),
        ];
        $before = $snapshot();

        // re-run: nothing added, nothing changed (existing admin kept, password untouched)
        $this->artisan('commerce:install', ['--connection' => 'scratch', '--admin-email' => 'owner@example.test', '--admin-password' => 'Another-Pass-77', '--no-interaction' => true])
            ->assertSuccessful();
        $this->assertSame($before, $snapshot());
        $this->assertTrue(Hash::check('Correct-Horse-9', $db->table('users')->where('email', 'owner@example.test')->value('password')));

        // the real shop tables were not touched
        $this->assertSame($real, $this->realCounts());
    }

    public function test_install_rejects_a_short_admin_password(): void
    {
        $this->artisan('commerce:install', ['--connection' => 'scratch', '--admin-email' => 'owner@example.test', '--admin-password' => 'short', '--no-interaction' => true])
            ->assertFailed();
        $this->assertFalse(DB::connection('scratch')->table('users')->exists());
    }

    private function realCounts(): array
    {
        return array_map(fn ($t) => DB::table($t)->count(), ['users' => 'users', 'settings' => 'settings', 'pages' => 'pages', 'menus' => 'menus', 'shipping_methods' => 'shipping_methods']);
    }
}
