<?php

namespace Pine\Commerce\Tests\Feature;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Pine\Commerce\Commerce;
use Pine\Commerce\Import\WooApi\UrlGuard;
use Pine\Commerce\Models\WooApiImport;
use Pine\Commerce\Tests\Concerns\InstallsNeutralStore;
use Pine\Commerce\Tests\Fixtures\FakeProcessRunner;
use Pine\Commerce\Tests\Fixtures\FakeWooShop;
use Pine\Commerce\Tests\TestCase;
use Pine\Commerce\Updater\ProcessRunner;

/**
 * Admin › Import › WooCommerce API: administrators only, the connection form (secrets encrypted and never shown
 * back), "Test connection", starting a run in the background (detached artisan process – faked), the progress page
 * and its status endpoint, cancel, resume, the log download and the sidebar entry.
 */
class WooApiImportAdminTest extends TestCase
{
    use InstallsNeutralStore;

    private FakeProcessRunner $runner;

    private string $workDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpNeutralStore();
        Storage::fake('public');
        $this->workDir = sys_get_temp_dir().'/woo-api-admin-'.bin2hex(random_bytes(4));
        config(['commerce.woo_api.path' => $this->workDir, 'commerce.woo_api.delay_ms' => 0, 'commerce.woo_api.per_page' => 2,
            'commerce.updater.php_binary' => PHP_BINARY]);
        $this->runner = new FakeProcessRunner;
        $this->app->instance(ProcessRunner::class, $this->runner);
    }

    protected function tearDown(): void
    {
        UrlGuard::$resolver = null;
        exec('rm -rf '.escapeshellarg($this->workDir));
        $this->tearDownNeutralStore();
        parent::tearDown();
    }

    private function manager(): \Pine\Commerce\Models\User
    {
        return Commerce::userModel()::forceCreate(['name' => 'Mia Manager', 'first_name' => 'Mia', 'email' => 'mia@shop.example.test',
            'password' => Hash::make('Manager-pass-123'), 'role' => 'manager', 'is_active' => true]);
    }

    private function saveConnection(array $extra = []): \Illuminate\Testing\TestResponse
    {
        return $this->post(route('admin.import.woo.connection'), $extra + ['mode' => 'rest', 'url' => 'old-shop.example.test/', 'key' => FakeWooShop::KEY,
            'secret' => FakeWooShop::SECRET, 'auth' => 'auto', 'verify_tls' => '1', 'action' => 'save']);
    }

    public function test_only_administrators_reach_the_import(): void
    {
        $this->get(route('admin.import.woo.index'))->assertRedirect(route('admin.login'));
        $this->actingAs($this->manager());
        $this->get(route('admin.import.woo.index'))->assertForbidden();
        $this->post(route('admin.import.woo.start'), ['entities' => ['products'], 'existing' => 'update'])->assertForbidden();
        $this->assertStringNotContainsString(route('admin.import.woo.index'), $this->get(route('admin.dashboard'))->getContent());

        $this->actingAs($this->neutralAdmin());
        $this->get(route('admin.import.woo.index'))->assertOk()->assertSee('Import from WooCommerce')->assertSee('No imports yet');
        $this->assertStringContainsString('href="'.route('admin.import.woo.index').'"', $this->get(route('admin.dashboard'))->getContent(), 'sidebar entry');

        config(['commerce.features.woo_api_import' => false]);
        $this->get(route('admin.import.woo.index'))->assertNotFound();
        $this->assertStringNotContainsString(route('admin.import.woo.index'), $this->get(route('admin.dashboard'))->getContent());
    }

    public function test_secrets_are_stored_encrypted_and_never_shown_again(): void
    {
        $this->actingAs($this->neutralAdmin());
        $this->saveConnection()->assertRedirect(route('admin.import.woo.index'))->assertSessionHas('success');

        $raw = DB::table('settings')->where('key', 'woo_api.secret')->value('value');
        $this->assertNotSame(FakeWooShop::SECRET, $raw);
        $this->assertSame(FakeWooShop::SECRET, Crypt::decryptString($raw));
        $this->assertSame('https://old-shop.example.test', \Pine\Commerce\Models\Setting::get('woo_api.url'));

        $html = $this->get(route('admin.import.woo.index'))->assertOk()->assertSee('Saved – leave empty to keep it')->getContent();
        $this->assertStringNotContainsString(FakeWooShop::SECRET, $html);
        $this->assertStringNotContainsString(FakeWooShop::KEY, $html);

        // an empty box keeps the saved value; "forget" removes it
        $this->saveConnection(['key' => '', 'secret' => '']);
        $this->assertSame(FakeWooShop::SECRET, Crypt::decryptString(DB::table('settings')->where('key', 'woo_api.secret')->value('value')));
        $this->saveConnection(['key' => '', 'secret' => '', 'secret_clear' => '1', 'key_clear' => '1']);
        $this->assertSame('', (string) DB::table('settings')->where('key', 'woo_api.secret')->value('value'));

        // a failed validation never flashes a secret back
        $this->post(route('admin.import.woo.connection'), ['mode' => 'rest', 'url' => '', 'key' => 'ck_x', 'secret' => 'cs_flash_me', 'auth' => 'auto'])
            ->assertSessionHasErrors('url');
        $this->assertNotContains('cs_flash_me', (array) session()->getOldInput());
    }

    public function test_test_connection_shows_the_shop_its_counts_and_permission_problems(): void
    {
        $shop = FakeWooShop::fake();
        $shop->override['wc/v3/customers'] = null; // simulate a missing endpoint
        $this->actingAs($this->neutralAdmin());
        $this->saveConnection(['action' => 'test'])->assertRedirect()->assertSessionHas('warning');
        $this->get(route('admin.import.woo.index'))->assertOk()
            ->assertSee('Old Shop &amp; Co', false)
            ->assertSee('9.9.0')
            ->assertSee('Products')
            ->assertSee('No access')
            ->assertSee('basic authentication');

        // wrong key: a clear error
        $this->saveConnection(['secret' => 'cs_wrong', 'action' => 'test'])->assertSessionHas('error', fn ($m) => str_contains($m, 'refused the API key') && ! str_contains($m, 'cs_wrong'));
    }

    public function test_an_import_runs_in_the_background_with_live_progress(): void
    {
        FakeWooShop::fake();
        $admin = $this->neutralAdmin();
        $this->actingAs($admin);
        $this->saveConnection();

        $response = $this->post(route('admin.import.woo.start'), ['entities' => ['categories', 'products'], 'existing' => 'update', 'images' => '0', 'notes' => '1']);
        $run = WooApiImport::query()->latest('id')->firstOrFail();
        $response->assertRedirect(route('admin.import.woo.show', $run))->assertSessionHas('success');
        $this->assertSame(['pending', $admin->email, 'admin', ['categories', 'products']], [$run->status, $run->user_email, $run->via, $run->option('entities')]);
        $this->assertSame(['commerce:import-woo-api', (string) $run->id], array_slice($this->runner->launched[0]['command'], -2), 'detached artisan process');

        $this->get(route('admin.import.woo.show', $run))->assertOk()->assertSee('Waiting to start', false);
        $this->getJson(route('admin.import.woo.status', $run))->assertOk()->assertJson(['status' => 'pending', 'finished' => false]);

        // a second import cannot start while one is open
        $this->post(route('admin.import.woo.start'), ['entities' => ['products'], 'existing' => 'update'])->assertSessionHas('error');

        // what the detached process does
        $this->assertSame(0, Artisan::call('commerce:import-woo-api', ['run' => $run->id]), Artisan::output());
        $status = $this->getJson(route('admin.import.woo.status', $run))->assertOk()->json();
        $this->assertSame(['completed', true], [$status['status'], $status['finished']]);
        $this->assertSame(['categories', 'products'], array_column($status['entities'], 'key'));
        $this->assertSame(3, $status['entities'][1]['created']);
        $this->assertNotEmpty($status['lines']);
        $this->assertSame(3, DB::table('products')->whereNotNull('wp_id')->count());

        // the log: downloadable, no secrets in it
        $log = $this->get(route('admin.import.woo.log', $run))->assertOk();
        $content = file_get_contents($log->baseResponse->getFile()->getPathname());
        $this->assertStringContainsString('Products done', $content);
        $this->assertStringNotContainsString(FakeWooShop::SECRET, $content);
        $this->assertStringNotContainsString(FakeWooShop::KEY, $content);

        $this->get(route('admin.import.woo.index'))->assertOk()->assertSee('#'.$run->id)->assertSee('Completed');
    }

    public function test_cancel_and_resume(): void
    {
        FakeWooShop::fake();
        $this->actingAs($this->neutralAdmin());
        $this->saveConnection();
        $this->post(route('admin.import.woo.start'), ['entities' => ['products'], 'existing' => 'update']);
        $run = WooApiImport::query()->latest('id')->firstOrFail();

        $this->post(route('admin.import.woo.cancel', $run))->assertRedirect(route('admin.import.woo.show', $run));
        $this->assertSame('cancelled', $run->fresh()->status, 'a pending run is cancelled at once');

        $this->post(route('admin.import.woo.resume', $run))->assertRedirect(route('admin.import.woo.show', $run))->assertSessionHas('success');
        $this->assertSame('pending', $run->fresh()->status);
        $this->assertCount(2, $this->runner->launched);

        // a "running" run whose process is gone becomes interrupted (resumable)
        $run->forceFill(['status' => 'running', 'pid' => 999999])->save();
        $this->getJson(route('admin.import.woo.status', $run))->assertJson(['status' => 'interrupted', 'resumable' => true]);
    }
}
