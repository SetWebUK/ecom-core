<?php

namespace Pine\Commerce\Theme;

use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Discovers themes, resolves the active theme chain and wires it into the view system.
 * Contract: docs/ARCHITECTURE.md §7.
 *
 * Discovery: base_path('themes/{slug}/theme.json') (client themes) and the package's resources/themes/{slug}
 * (core themes – "default"). A client theme with the same slug as a core theme wins.
 *
 * Active theme, first match:
 *   1. a staff preview for this request (PreviewTheme middleware → preview())
 *   2. the admin choice, setting "theme.active" (Admin › Settings › Theme; applied once the app has booted)
 *   3. config('commerce.theme') ← env COMMERCE_THEME, default "default"
 *
 * View paths (registerViewPaths()):
 *   config('view.paths') = [resource_path('views'), themes/{active}/views, themes/{parent}/views, …, default/views]
 *   view namespace "theme" = the same list minus resource_path('views')
 *
 * The manifest (every theme.json + its config/*.php) is cached in bootstrap/cache/commerce-themes.php by
 * `php artisan commerce:theme:cache` (hooked into `php artisan optimize`).
 */
class ThemeManager
{
    public const DEFAULT = 'default';

    /** @var array<string, array>|null slug => manifest (theme.json + path + config) */
    protected ?array $manifests = null;

    /** @var array<string, Theme> */
    protected array $themes = [];

    /** @var array<string, ThemeDefinition|false> */
    protected array $definitions = [];

    /** @var array<string, bool> slugs whose PSR-4 autoload map is registered */
    protected array $autoloaded = [];

    protected ?string $activeSlug = null;

    protected ?string $previewSlug = null;

    /** @var array<string, bool> definitions whose boot() ran */
    protected array $booted = [];

    protected bool $warnedMissingAsset = false;

    public function __construct(protected Application $app) {}

    // ------------------------------------------------------------------------------------------------ discovery

    /** @var list<string> extra theme directories searched first (addRoot()) */
    protected array $extraRoots = [];

    /** Search another directory for themes, before themes/ (tests, multi-site setups). Clears the discovered manifest. */
    public function addRoot(string $dir): void
    {
        array_unshift($this->extraRoots, rtrim($dir, '/'));
        $this->manifests = null;
        $this->themes = [];
    }

    /** Directories searched for themes, highest precedence first. */
    public function roots(): array
    {
        return [
            ...$this->extraRoots,
            $this->app->basePath('themes'),
            dirname(__DIR__, 2).'/resources/themes',
        ];
    }

    public function cachePath(): string
    {
        return $this->app->bootstrapPath('cache/commerce-themes.php');
    }

    /** @return array<string, array> */
    public function manifests(): array
    {
        if ($this->manifests !== null) {
            return $this->manifests;
        }
        $cache = $this->cachePath();
        if ($this->extraRoots === [] && is_file($cache)) {
            $cached = require $cache;
            if (is_array($cached) && ($cached['version'] ?? null) === 1) {
                return $this->manifests = $cached['themes'];
            }
        }

        return $this->manifests = $this->scan();
    }

    /** Read every theme.json (and config/*.php) from disk. */
    public function scan(): array
    {
        $found = [];
        $core = dirname(__DIR__, 2).'/resources/themes';
        foreach ($this->roots() as $root) {
            foreach (glob($root.'/*/theme.json') ?: [] as $json) {
                $path = dirname($json);
                $data = json_decode((string) file_get_contents($json), true);
                if (! is_array($data)) {
                    Log::warning('Theme '.basename($path).': theme.json is not valid JSON – ignored.');

                    continue;
                }
                $slug = (string) ($data['slug'] ?? basename($path));
                if (isset($found[$slug])) {
                    continue; // an app theme shadows a core theme of the same slug
                }
                $data['slug'] = $slug;
                $data['path'] = $path;
                $data['core'] = $root === $core;
                $data['config'] = [];
                foreach (glob($path.'/config/*.php') ?: [] as $file) {
                    $value = (static fn (string $__file) => require $__file)($file); // own scope: config files may use local variables
                    if (is_array($value)) {
                        $data['config'][basename($file, '.php')] = $value;
                    }
                }
                $found[$slug] = $data;
            }
        }
        ksort($found);

        return $found;
    }

    /** Write the manifest cache (commerce:theme:cache). */
    public function writeCache(): string
    {
        $this->manifests = null;
        $this->themes = [];
        $manifests = $this->scan();
        $file = $this->cachePath();
        file_put_contents($file, '<?php return '.var_export(['version' => 1, 'themes' => $manifests], true).';'.PHP_EOL);
        $this->manifests = $manifests;

        return $file;
    }

    public function clearCache(): void
    {
        @unlink($this->cachePath());
        $this->manifests = null;
        $this->themes = [];
    }

    public function exists(string $slug): bool
    {
        return isset($this->manifests()[$slug]);
    }

    public function get(string $slug): Theme
    {
        if (isset($this->themes[$slug])) {
            return $this->themes[$slug];
        }
        $manifest = $this->manifests()[$slug] ?? null;
        if ($manifest === null) {
            throw new RuntimeException("Theme [{$slug}] is not installed (looked in themes/ and the pine/commerce core themes).");
        }

        return $this->themes[$slug] = new Theme($slug, $manifest['path'], $manifest, $this);
    }

    /** @return array<string, Theme> every installed theme, keyed by slug */
    public function all(): array
    {
        $all = [];
        foreach (array_keys($this->manifests()) as $slug) {
            $all[$slug] = $this->get($slug);
        }

        return $all;
    }

    // ------------------------------------------------------------------------------------------------ active theme

    /** Slug configured for this install (setting "theme.active" once applied, else config commerce.theme). */
    public function configuredSlug(): string
    {
        return $this->activeSlug ?? $this->validSlug((string) (config('commerce.theme') ?: self::DEFAULT));
    }

    /** Slug rendering this request (a staff preview wins over the configured theme). */
    public function activeSlug(): string
    {
        return $this->previewSlug ?? $this->configuredSlug();
    }

    public function active(): Theme
    {
        return $this->get($this->activeSlug());
    }

    public function previewing(): ?string
    {
        return $this->previewSlug;
    }

    /**
     * Theme chain for a slug (default: the active theme): the theme, its parents in order, "default" last.
     *
     * @return list<Theme>
     */
    public function chain(?string $slug = null): array
    {
        $slug ??= $this->activeSlug();
        $chain = [];
        $seen = [];
        $current = $slug;
        while ($current !== null) {
            if (isset($seen[$current])) {
                throw new RuntimeException('Theme parent chain has a cycle: '.implode(' → ', array_keys($seen)).' → '.$current);
            }
            if ($chain !== [] && ! $this->exists($current)) {
                $child = end($chain)->slug;
                throw new RuntimeException("Theme [{$child}] declares parent theme [{$current}] in theme.json, but no such theme is installed (looked in themes/ and the pine/commerce core themes).");
            }
            $seen[$current] = true;
            $theme = $this->get($current);
            $chain[] = $theme;
            $current = $theme->parent();
            if ($current === null && $theme->slug !== self::DEFAULT && $this->exists(self::DEFAULT)) {
                $current = self::DEFAULT; // "default" is always appended last
            }
        }

        return $chain;
    }

    /**
     * An unknown slug is an exception in `local` (and in tests of the resolver), and a logged error + fallback to
     * "default" everywhere else, so a typo in .env never takes a live shop down.
     */
    protected function validSlug(string $slug): string
    {
        if ($this->exists($slug)) {
            $problem = $this->chainProblem($slug);
            if ($problem === null) {
                return $slug;
            }
            if ($this->app->environment('local')) {
                throw new RuntimeException("Theme [{$slug}] cannot be used: {$problem}");
            }
            Log::error("Active theme [{$slug}] cannot be used ({$problem}) – falling back to the default theme.");

            return self::DEFAULT;
        }
        if ($this->app->environment('local')) {
            throw new RuntimeException("COMMERCE_THEME is set to [{$slug}], but no such theme is installed.");
        }
        Log::error("Active theme [{$slug}] is not installed – falling back to the default theme.");

        return self::DEFAULT;
    }

    /** Why a theme's parent chain cannot be resolved (missing parent, cycle), or null when it is usable. */
    public function chainProblem(string $slug): ?string
    {
        if (! $this->exists($slug)) {
            return "theme [{$slug}] is not installed";
        }
        try {
            $this->chain($slug);
        } catch (RuntimeException $e) {
            return $e->getMessage();
        }

        return null;
    }

    /** Installed and its parent chain resolves (no missing parent, no cycle). */
    public function usable(string $slug): bool
    {
        return $this->chainProblem($slug) === null;
    }

    /** Switch the configured theme for the rest of this process (setting "theme.active", tests). */
    public function activate(string $slug): void
    {
        $this->activeSlug = $this->validSlug($slug);
        $this->apply();
    }

    /** Render this request with another theme (staff preview). null ends the preview. */
    public function preview(?string $slug): void
    {
        $this->previewSlug = $slug !== null && $this->usable($slug) ? $slug : null;
        $this->apply();
    }

    /** Apply the admin's choice (setting "theme.active") – called once the application has booted. */
    public function applyStoredChoice(): void
    {
        try {
            $stored = trim((string) setting('theme.active', ''));
        } catch (\Throwable $e) {
            $stored = '';
        }
        if ($stored === '' || $stored === $this->configuredSlug()) {
            return;
        }
        if (($problem = $this->chainProblem($stored)) !== null) {
            Log::error("Setting theme.active names theme [{$stored}], which cannot be used ({$problem}) – ignored.");

            return;
        }
        $this->activate($stored);
    }

    /** Forget the per-process choice (tests). */
    public function reset(): void
    {
        $this->activeSlug = null;
        $this->previewSlug = null;
        $this->manifests = null;
        $this->themes = [];
        $this->apply();
    }

    // ------------------------------------------------------------------------------------------------ views

    /** @return list<string> view directories of the active chain (without resource_path('views')) */
    public function viewPaths(?string $slug = null): array
    {
        return array_values(array_filter(array_map(fn (Theme $t) => $t->viewsPath(), $this->chain($slug)), 'is_dir'));
    }

    /**
     * config('view.paths') = [resource_path('views'), …chain views…]; the "theme" view namespace = the chain alone.
     * Safe to call in register() (before the view factory exists) and again later (preview / settings switch).
     */
    public function registerViewPaths(): void
    {
        $themePaths = $this->viewPaths();
        $appPath = $this->app->resourcePath('views');
        $paths = array_values(array_unique(array_merge([$appPath], $themePaths)));

        $this->app['config']->set('view.paths', $paths);

        if ($this->app->resolved('view')) {
            // "view.finder" is a plain binding (a new instance per resolve): update the factory's own finder
            $view = $this->app['view'];
            $finder = $view->getFinder();
            $finder->setPaths($paths);
            $finder->flush();
            $view->replaceNamespace('theme', $themePaths);
        } else {
            $this->app->afterResolving('view', function ($view) {
                $view->replaceNamespace('theme', $this->viewPaths());
            });
        }
    }

    protected function apply(): void
    {
        $this->registerViewPaths();
        if ($this->app->isBooted()) {
            $this->bootDefinitions();
        }
    }

    /** Debug: the file a view name resolves to under the active chain. */
    public function find(string $view): ?string
    {
        try {
            return $this->app['view']->getFinder()->find($view);
        } catch (\InvalidArgumentException $e) {
            return null;
        }
    }

    // ------------------------------------------------------------------------------------------------ Theme.php

    /** Instance of the theme's ThemeDefinition (theme.json "class", file themes/{slug}/Theme.php), or null. */
    public function definition(Theme $theme): ?ThemeDefinition
    {
        if (array_key_exists($theme->slug, $this->definitions)) {
            return $this->definitions[$theme->slug] ?: null;
        }
        $this->registerAutoload($theme);
        $class = $theme->manifest()['class'] ?? null;
        if (! is_string($class) || $class === '') {
            return ($this->definitions[$theme->slug] = false) ?: null;
        }
        if (! class_exists($class, false) && is_file($theme->path.'/Theme.php')) {
            require_once $theme->path.'/Theme.php';
        }
        if (! class_exists($class) || ! is_subclass_of($class, ThemeDefinition::class)) {
            throw new RuntimeException("Theme [{$theme->slug}]: class {$class} must exist (Theme.php) and extend ".ThemeDefinition::class.'.');
        }

        return $this->definitions[$theme->slug] = new $class($theme);
    }

    /** @return list<ThemeDefinition> definitions of the active chain, parents first */
    public function definitions(): array
    {
        return array_values(array_filter(array_map(fn (Theme $t) => $t->definition(), array_reverse($this->chain()))));
    }

    /** Runtime PSR-4 map from theme.json "autoload" (no composer change needed). */
    protected function registerAutoload(Theme $theme): void
    {
        if (isset($this->autoloaded[$theme->slug])) {
            return;
        }
        $this->autoloaded[$theme->slug] = true;
        foreach ((array) ($theme->manifest()['autoload'] ?? []) as $prefix => $dir) {
            $prefix = trim((string) $prefix, '\\').'\\';
            $base = rtrim($theme->path.'/'.trim((string) $dir, '/'), '/').'/';
            spl_autoload_register(function (string $class) use ($prefix, $base) {
                if (str_starts_with($class, $prefix)) {
                    $file = $base.str_replace('\\', '/', substr($class, strlen($prefix))).'.php';
                    if (is_file($file)) {
                        require_once $file;
                    }
                }
            });
        }
    }

    public function registerDefinitions(): void
    {
        foreach ($this->definitions() as $definition) {
            $definition->register($this->app);
        }
    }

    public function bootDefinitions(): void
    {
        foreach ($this->definitions() as $definition) {
            $slug = $definition->theme()->slug;
            if (! isset($this->booted[$slug])) {
                $this->booted[$slug] = true;
                $definition->boot();
                $this->registerComposers($definition);
            }
        }
    }

    /**
     * View composers of a theme (ThemeDefinition::composers()). Each runs only while that theme is in the active chain
     * (a staff preview or an admin theme switch never leaks another theme's composers). A bare view name also matches
     * its theme:: form, since core renders contract views as theme::{name}.
     */
    protected function registerComposers(ThemeDefinition $definition): void
    {
        $slug = $definition->theme()->slug;
        foreach ($definition->composers() as $views => $composer) {
            $names = [];
            foreach (array_map('trim', explode(',', (string) $views)) as $name) {
                $names[] = $name;
                if (! str_contains($name, '::')) {
                    $names[] = 'theme::'.$name;
                }
            }
            $this->app['view']->composer($names, function ($view) use ($composer, $slug) {
                if (! in_array($slug, array_map(fn (Theme $t) => $t->slug, $this->chain()), true)) {
                    return;
                }
                is_string($composer) ? $this->app->make($composer)->compose($view) : $composer($view);
            });
        }
    }

    /** Theme routes (ThemeDefinition::routes()) of the configured chain. */
    public function loadRoutes(): void
    {
        foreach ($this->definitions() as $definition) {
            $definition->routes();
        }
    }

    /** Let the active chain adjust core-computed body classes (child theme last). */
    public function bodyClass(string $key, string $classes, array $data = []): string
    {
        foreach ($this->definitions() as $definition) {
            $classes = $definition->bodyClass($key, $classes, $data);
        }

        return $classes;
    }

    // ------------------------------------------------------------------------------------------------ assets / config / settings

    /**
     * URL of a published theme asset: the first theme of the chain whose PUBLISHED file exists wins, cache-busted with
     * ?v=filemtime. Missing everywhere → URL under the active theme without ?v (+ one log warning per request).
     */
    public function asset(string $path, ?Theme $from = null): string
    {
        $path = ltrim($path, '/');
        $chain = $from ? $from->lineage() : $this->chain();
        if (($relative = $this->publishedAsset($path, $from)) !== null) {
            return asset($relative).'?v='.filemtime(public_path($relative));
        }
        if (! $this->warnedMissingAsset) {
            $this->warnedMissingAsset = true;
            Log::warning("theme_asset({$path}): not published for theme [{$chain[0]->slug}] – run php artisan commerce:theme:publish.");
        }

        return asset($chain[0]->publicPath().'/'.$path);
    }

    /**
     * Path (relative to public/) of the first PUBLISHED copy of a theme asset along the chain, or null.
     * E.g. publishedAsset('css/site.css') = 'assets/css/site.css' for a theme with public_path "assets".
     */
    public function publishedAsset(string $path, ?Theme $from = null): ?string
    {
        $path = ltrim($path, '/');
        foreach ($from ? $from->lineage() : $this->chain() as $theme) {
            $relative = $theme->publicPath().'/'.$path;
            if (is_file(public_path($relative))) {
                return $relative;
            }
        }

        return null;
    }

    public function config(string $key, $default = null)
    {
        return $this->active()->config($key, $default);
    }

    /** setting('theme.{slug}.{key}') ?? theme.json default (lineage) ?? $default */
    public function setting(string $key, $default = null)
    {
        $theme = $this->active();
        $value = setting($theme->settingKey($key));
        if ($value !== null && $value !== '') {
            return $value;
        }
        $schema = $theme->settingsSchema()[$key] ?? null;

        return is_array($schema) && array_key_exists('default', $schema) ? $schema['default'] : $default;
    }
}
