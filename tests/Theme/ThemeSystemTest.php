<?php

namespace Pine\Commerce\Tests\Theme;

use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\Artisan;
use Pine\Commerce\Theme\ThemeContract;
use Pine\Commerce\Theme\ThemeManager;
use RuntimeException;
use Pine\Commerce\Tests\TestCase;

/**
 * Theme resolution (ARCHITECTURE.md §7): chain order, child overrides, theme_asset(), theme_config(), theme_setting()
 * defaults, Theme.php hooks, the manifest cache, commerce:theme:make / :publish / :check. Uses temporary themes in a
 * scratch directory (ThemeManager::addRoot) and a temporary public path – never the client's themes.
 */
class ThemeSystemTest extends TestCase
{
    protected string $root;

    protected string $public;

    protected ThemeManager $themes;

    protected function setUp(): void
    {
        parent::setUp();
        $this->root = sys_get_temp_dir().'/commerce-themes-'.bin2hex(random_bytes(4));
        $this->public = $this->root.'/public';
        $files = new Filesystem;
        $files->ensureDirectoryExists($this->public);
        $this->app->usePublicPath($this->public);

        $this->makeTheme('acme-base', ['parent' => 'default', 'public_path' => 'themes/acme-base',
            'settings' => ['accent' => ['type' => 'color', 'default' => '#ff0000'], 'tagline' => ['type' => 'text', 'default' => 'Base tagline']]], [
            'views/partials/greeting.blade.php' => 'base greeting',
            'views/partials/only-base.blade.php' => 'only in base',
            'views/partials/composed.blade.php' => '{{ $composed ?? \'not composed\' }}',
            'assets/css/base.css' => 'body{}',
            'assets/css/shared.css' => '/* base */',
            'config/menus.php' => "<?php return ['main' => ['base-main'], 'footer' => ['base-footer']];",
        ]);
        $this->makeTheme('acme', ['parent' => 'acme-base', 'class' => 'CommerceTestThemes\\Acme\\Theme',
            'settings' => ['tagline' => ['type' => 'text', 'default' => 'Child tagline']]], [
            'views/partials/greeting.blade.php' => 'child greeting',
            'assets/css/shared.css' => '/* child */',
            'config/menus.php' => "<?php return ['main' => ['child-main']];",
            'Theme.php' => "<?php namespace CommerceTestThemes\\Acme; class Theme extends \\Pine\\Commerce\\Theme\\ThemeDefinition { public static int \$booted = 0; public function boot(): void { static::\$booted++; } public function bodyClass(string \$key, string \$classes, array \$data): string { return \$classes.' acme-'.\$key; } public function composers(): array { return ['partials.composed' => fn (\$view) => \$view->with('composed', 'composed by acme')]; } }",
        ]);

        $this->themes = $this->app->make(ThemeManager::class);
        $this->themes->addRoot($this->root.'/themes');
    }

    protected function tearDown(): void
    {
        $this->themes->reset();
        (new Filesystem)->deleteDirectory($this->root);
        parent::tearDown();
    }

    protected function makeTheme(string $slug, array $manifest, array $files): void
    {
        $dir = $this->root.'/themes/'.$slug;
        $fs = new Filesystem;
        $fs->ensureDirectoryExists($dir);
        $fs->put($dir.'/theme.json', json_encode(array_merge(['name' => ucfirst($slug), 'slug' => $slug, 'version' => '1.0.0', 'supports' => []], $manifest)));
        foreach ($files as $path => $content) {
            $fs->ensureDirectoryExists(dirname($dir.'/'.$path));
            $fs->put($dir.'/'.$path, $content);
        }
    }

    public function test_chain_resolves_child_parent_then_default(): void
    {
        $this->assertSame(['acme', 'acme-base', 'default'], array_map(fn ($t) => $t->slug, $this->themes->chain('acme')));
        $this->assertSame(['default'], array_map(fn ($t) => $t->slug, $this->themes->chain('default')));
    }

    public function test_view_paths_follow_the_chain_after_the_app_override_directory(): void
    {
        $this->themes->activate('acme');
        $paths = config('view.paths');
        $this->assertSame(resource_path('views'), $paths[0]);
        $this->assertSame($this->root.'/themes/acme/views', $paths[1]);
        $this->assertSame($this->root.'/themes/acme-base/views', $paths[2]);
        $this->assertStringEndsWith('resources/themes/default/views', end($paths));
    }

    public function test_child_theme_overrides_a_single_view_and_inherits_the_rest(): void
    {
        $this->themes->activate('acme');
        $this->assertSame('child greeting', trim(view('partials.greeting')->render()));
        $this->assertSame('child greeting', trim(view('theme::partials.greeting')->render()));
        $this->assertSame('only in base', trim(view('partials.only-base')->render()));
        // default theme views come last in the chain
        $this->assertStringEndsWith('resources/themes/default/views/partials/pagination.blade.php', $this->themes->find('partials.pagination'));

        $this->themes->activate('acme-base');
        $this->assertSame('base greeting', trim(view('partials.greeting')->render()));
    }

    public function test_theme_config_merges_child_over_parent_per_top_level_key(): void
    {
        $this->themes->activate('acme');
        $this->assertSame(['child-main'], theme_config('menus.main'));
        $this->assertSame(['base-footer'], theme_config('menus.footer'));
        $this->assertSame('fallback', theme_config('menus.missing', 'fallback'));
        $this->assertSame('fallback', theme_config('nofile.key', 'fallback'));
    }

    public function test_theme_setting_falls_back_to_the_nearest_theme_json_default(): void
    {
        $this->themes->activate('acme');
        $this->assertSame('Child tagline', theme_setting('tagline'));
        $this->assertSame('#ff0000', theme_setting('accent'));
        $this->assertSame('x', theme_setting('unknown', 'x'));
        $this->assertSame('theme.acme.tagline', $this->themes->active()->settingKey('tagline'));
    }

    public function test_theme_asset_uses_the_first_published_copy_in_the_chain(): void
    {
        $this->themes->activate('acme');
        $this->artisan('commerce:theme:publish', ['slug' => 'acme'])->assertSuccessful();

        $this->assertFileExists($this->public.'/themes/acme/css/shared.css');
        $this->assertFileExists($this->public.'/themes/acme-base/css/base.css');
        $this->assertFileExists($this->public.'/themes/default/css/app.css');
        $this->assertFileExists($this->public.'/themes/acme/.commerce-theme');
        $this->assertFalse(is_link($this->public.'/themes/acme'));

        $this->assertMatchesRegularExpression('#/themes/acme/css/shared\.css\?v=\d+$#', theme_asset('css/shared.css'));
        $this->assertMatchesRegularExpression('#/themes/acme-base/css/base\.css\?v=\d+$#', theme_asset('css/base.css'));
        $this->assertMatchesRegularExpression('#/themes/default/css/app\.css\?v=\d+$#', theme_asset('css/app.css'));
        $this->assertStringEndsWith('/themes/acme/css/missing.css', theme_asset('css/missing.css'));
    }

    public function test_publish_refuses_a_directory_owned_by_another_theme_and_prunes_only_on_request(): void
    {
        $this->artisan('commerce:theme:publish', ['slug' => 'acme-base'])->assertSuccessful();
        file_put_contents($this->public.'/themes/acme-base/stale.txt', 'old');
        $this->artisan('commerce:theme:publish', ['slug' => 'acme-base'])->assertSuccessful();
        $this->assertFileExists($this->public.'/themes/acme-base/stale.txt');
        $this->artisan('commerce:theme:publish', ['slug' => 'acme-base', '--prune' => true])->assertSuccessful();
        $this->assertFileDoesNotExist($this->public.'/themes/acme-base/stale.txt');

        file_put_contents($this->public.'/themes/acme-base/.commerce-theme', json_encode(['slug' => 'someone-else']));
        $this->artisan('commerce:theme:publish', ['slug' => 'acme-base'])->assertFailed();
    }

    public function test_theme_php_hooks_are_loaded_for_the_active_chain(): void
    {
        $this->themes->activate('acme');
        $this->assertSame('archive acme-catalog.shop', $this->themes->bodyClass('catalog.shop', 'archive'));
        $this->assertGreaterThanOrEqual(1, \CommerceTestThemes\Acme\Theme::$booted);
    }

    public function test_theme_view_composers_apply_only_while_their_theme_is_in_the_active_chain(): void
    {
        $this->themes->activate('acme');
        $this->assertSame('composed by acme', trim(view('partials.composed')->render()));
        $this->assertSame('composed by acme', trim(view('theme::partials.composed')->render()), 'theme:: form too');

        $this->themes->activate('acme-base'); // the child (and its composer) left the chain
        $this->assertSame('not composed', trim(view('partials.composed')->render()));
    }

    public function test_unknown_theme_falls_back_to_default_outside_local_and_throws_in_local(): void
    {
        config(['commerce.theme' => 'no-such-theme']);
        $this->themes->reset();
        $this->themes->addRoot($this->root.'/themes');
        $this->assertSame('default', $this->themes->activeSlug());

        $this->app['env'] = 'local';
        try {
            $this->expectException(RuntimeException::class);
            $this->themes->reset(); // re-resolves the active theme
        } finally {
            $this->app['env'] = 'testing';
        }
    }

    public function test_parent_cycles_are_rejected(): void
    {
        $this->makeTheme('loop-a', ['parent' => 'loop-b'], []);
        $this->makeTheme('loop-b', ['parent' => 'loop-a'], []);
        $this->themes->addRoot($this->root.'/themes');
        $this->expectException(RuntimeException::class);
        $this->themes->chain('loop-a');
    }

    public function test_a_missing_parent_is_a_clear_error_and_never_goes_live(): void
    {
        $this->makeTheme('orphan', ['parent' => 'not-installed-parent'], []);
        $this->makeTheme('loop-x', ['parent' => 'loop-x'], []);
        $this->themes->addRoot($this->root.'/themes');

        try {
            $this->themes->chain('orphan');
            $this->fail('A missing parent must throw.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('[orphan] declares parent theme [not-installed-parent]', $e->getMessage());
        }
        $this->assertFalse($this->themes->usable('orphan'));
        $this->assertFalse($this->themes->usable('loop-x'));
        $this->assertTrue($this->themes->usable('acme'));

        // outside local: logged + default instead of taking the shop down; preview ignores it
        config(['commerce.theme' => 'orphan']);
        $this->themes->reset();
        $this->themes->addRoot($this->root.'/themes');
        $this->assertSame('default', $this->themes->activeSlug());
        $this->themes->preview('loop-x');
        $this->assertNull($this->themes->previewing());
    }

    public function test_preview_overrides_the_configured_theme_for_this_process_only(): void
    {
        $this->themes->activate('acme-base');
        $this->themes->preview('acme');
        $this->assertSame('acme', $this->themes->activeSlug());
        $this->assertSame('acme-base', $this->themes->configuredSlug());
        $this->themes->preview(null);
        $this->assertSame('acme-base', $this->themes->activeSlug());
        $this->themes->preview('not-installed');
        $this->assertSame('acme-base', $this->themes->activeSlug());
    }

    public function test_default_theme_satisfies_the_contract_and_child_themes_inherit_it(): void
    {
        $this->assertSame([], ThemeContract::check($this->themes, 'default'));
        $this->assertSame([], ThemeContract::check($this->themes, 'acme'));
        $this->artisan('commerce:theme:check', ['slug' => 'default'])->assertSuccessful();
    }

    public function test_manifest_cache_round_trip(): void
    {
        $manager = new ThemeManager($this->app);
        $file = $manager->writeCache();
        try {
            $this->assertFileExists($file);
            $cached = require $file;
            $this->assertArrayHasKey('default', $cached['themes']);
            $this->assertTrue((new ThemeManager($this->app))->exists('default'));
        } finally {
            $manager->clearCache();
        }
        $this->assertFileDoesNotExist($file);
    }

    public function test_theme_make_scaffolds_a_child_theme(): void
    {
        $slug = 'zz-scaffold-'.bin2hex(random_bytes(3));
        $dir = base_path('themes/'.$slug);
        try {
            $this->artisan('commerce:theme:make', ['slug' => $slug, '--parent' => 'default'])->assertSuccessful();
            $this->assertFileExists($dir.'/theme.json');
            $this->assertFileExists($dir.'/Theme.php');
            $this->assertFileExists($dir.'/assets/css/theme.css');
            $this->assertStringContainsString('partials/header.blade.php', file_get_contents($dir.'/views/README.md'));
            $manifest = json_decode(file_get_contents($dir.'/theme.json'), true);
            $this->assertSame('default', $manifest['parent']);
            $this->themes->reset();
            $this->assertSame([], ThemeContract::check($this->themes, $slug));
            $this->artisan('commerce:theme:make', ['slug' => $slug])->assertFailed();
        } finally {
            (new Filesystem)->deleteDirectory($dir);
        }
    }
}
