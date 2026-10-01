<?php

namespace Pine\Commerce\Tests\Console;

use Illuminate\Support\Facades\File;
use Pine\Commerce\Tests\TestCase;

class NewClientCommandTest extends TestCase
{
    private string $target;

    protected function setUp(): void
    {
        parent::setUp();
        $this->target = storage_path('framework/testing/new-client-'.uniqid());
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->target);
        parent::tearDown();
    }

    public function test_scaffolds_a_client_project_from_the_skeleton(): void
    {
        $this->artisan('commerce:new-client', ['path' => $this->target, '--name' => 'Acme "Tools"', '--slug' => 'acme'])->assertSuccessful();

        foreach (['composer.json', '.env', '.env.example', '.gitignore', 'artisan', 'bootstrap/app.php', 'bootstrap/providers.php',
            'config/commerce.php', 'config/database.php', 'config/filesystems.php', 'app/Providers/ClientServiceProvider.php',
            'app/Models/User.php', 'public/index.php', 'public/.htaccess', 'public/storage/.htaccess', 'routes/web.php', 'themes/README.md'] as $file) {
            $this->assertFileExists($this->target.'/'.$file);
        }
        $this->assertFileDoesNotExist($this->target.'/gitignore.stub');

        $composer = json_decode(File::get($this->target.'/composer.json'), true);
        $this->assertIsArray($composer, 'composer.json must be valid JSON after the placeholders are filled');
        $this->assertSame('pine-clients/acme', $composer['name']);
        $this->assertSame('path', $composer['repositories'][0]['type']);
        $this->assertFileExists($composer['repositories'][0]['url'].'/src/CommerceServiceProvider.php');
        $this->assertSame('*@dev', $composer['require']['pine/commerce']);

        // the client theme does not exist yet: stay on default (no "theme not installed" errors) and name the next step
        $this->assertStringContainsString('COMMERCE_THEME=default', File::get($this->target.'/.env'));
        $this->assertStringContainsString('commerce:theme:make acme --parent=default', File::get($this->target.'/.env'));
        // import artefacts (quarantined uploads, Elementor CSS, URL maps) must never be committed
        $this->assertFileExists($this->target.'/storage/app/.gitignore');
        $this->assertFileExists($this->target.'/storage/app/private/.gitignore');
        $this->assertStringNotContainsString('{{', File::get($this->target.'/config/commerce.php'));
        $this->assertStringContainsString('RewriteRule ^ index.php [L]', File::get($this->target.'/public/.htaccess'));
        // data-safety guards every client keeps (docs/PLAYBOOK.md part 4)
        $this->assertStringContainsString('DB::prohibitDestructiveCommands()', File::get($this->target.'/app/Providers/ClientServiceProvider.php'));
        $this->assertStringContainsString('Refusing to refresh the database', File::get($this->target.'/tests/TestCase.php'));
        $this->assertStringContainsString('/auth.json', File::get($this->target.'/.gitignore'));
    }

    public function test_repo_option_writes_a_vcs_repository_for_production(): void
    {
        $this->artisan('commerce:new-client', ['path' => $this->target, '--name' => 'Acme', '--repo' => 'git@github.com:SetWebUK/ecom-core.git'])
            ->expectsOutputToContain('VCS')
            ->assertSuccessful();
        $composer = json_decode(File::get($this->target.'/composer.json'), true);
        $this->assertSame([['type' => 'vcs', 'url' => 'git@github.com:SetWebUK/ecom-core.git']], $composer['repositories']);
        $this->assertSame('^1.0', $composer['require']['pine/commerce']);
    }

    public function test_path_option_and_custom_constraint(): void
    {
        $package = dirname(__DIR__, 2);
        $this->artisan('commerce:new-client', ['path' => $this->target, '--name' => 'Acme', '--path' => $package, '--constraint' => '1.0.x-dev'])
            ->assertSuccessful();
        $composer = json_decode(File::get($this->target.'/composer.json'), true);
        $this->assertSame('path', $composer['repositories'][0]['type']);
        $this->assertSame(realpath($package), $composer['repositories'][0]['url']);
        $this->assertTrue($composer['repositories'][0]['options']['symlink']);
        $this->assertSame('1.0.x-dev', $composer['require']['pine/commerce']);
    }

    public function test_repo_and_path_together_or_a_bad_constraint_are_refused(): void
    {
        $this->artisan('commerce:new-client', ['path' => $this->target, '--repo' => 'git@github.com:SetWebUK/ecom-core.git', '--path' => dirname(__DIR__, 2)])->assertFailed();
        $this->artisan('commerce:new-client', ['path' => $this->target, '--constraint' => '^1.0", "evil": "x'])->assertFailed();
        $this->artisan('commerce:new-client', ['path' => $this->target, '--path' => '/nonexistent/commerce'])->assertFailed();
        $this->assertFileDoesNotExist($this->target.'/composer.json');
    }

    public function test_vcs_repository_and_refusals(): void
    {
        $this->artisan('commerce:new-client', ['path' => $this->target, '--name' => 'Acme', '--vcs' => 'git@git.example.com:pine/commerce.git'])->assertSuccessful();
        $composer = json_decode(File::get($this->target.'/composer.json'), true);
        $this->assertSame(['type' => 'vcs', 'url' => 'git@git.example.com:pine/commerce.git'], $composer['repositories'][0]);
        $this->assertSame('^1.0', $composer['require']['pine/commerce']);

        // non-empty target
        $this->artisan('commerce:new-client', ['path' => $this->target])->assertFailed();
        // inside this application
        $this->artisan('commerce:new-client', ['path' => base_path('client-x')])->assertFailed();
        $this->assertDirectoryDoesNotExist(base_path('client-x'));
    }
}
