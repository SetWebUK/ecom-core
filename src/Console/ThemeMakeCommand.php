<?php

namespace Pine\Commerce\Console;

use Illuminate\Console\Command;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Str;
use Pine\Commerce\Theme\ThemeContract;
use Pine\Commerce\Theme\ThemeManager;

/**
 * Scaffold a child theme in themes/{slug}:
 *
 *   theme.json            name/slug/parent/supports (inherited from the parent), public_path themes/{slug}
 *   Theme.php             optional hooks (ThemeDefinition subclass) – body classes, composers, shortcodes, routes
 *   config/.gitkeep       theme config files (menus.php, home.php, blocks.php, product.php, seo.php) override the parent's
 *   views/README.md       every view a child can override (same relative path as in the parent)
 *   assets/css/theme.css  loaded by the default theme's layout after its own CSS – brand overrides go here
 *
 * --copy copies the parent's views and assets for a full fork instead.
 */
class ThemeMakeCommand extends Command
{
    protected $signature = 'commerce:theme:make
        {slug : Directory name / slug of the new theme (a-z, 0-9, dashes)}
        {--parent=default : Parent theme}
        {--name= : Display name (default: from the slug)}
        {--copy : Copy all of the parent\'s views and assets (a full fork) instead of starting empty}';

    protected $description = 'Scaffold a new storefront (child) theme in themes/';

    public function handle(ThemeManager $themes, Filesystem $files): int
    {
        $slug = (string) $this->argument('slug');
        if (! preg_match('/^[a-z0-9]+(-[a-z0-9]+)*$/', $slug)) {
            $this->error('The slug may only contain a-z, 0-9 and single dashes (e.g. "acme-shop").');

            return self::FAILURE;
        }
        $parentSlug = (string) $this->option('parent');
        if (! $themes->exists($parentSlug)) {
            $this->error("Parent theme [{$parentSlug}] is not installed.");

            return self::FAILURE;
        }
        $path = base_path('themes/'.$slug);
        if ($files->exists($path) || $themes->exists($slug)) {
            $this->error("Theme [{$slug}] already exists.");

            return self::FAILURE;
        }
        $parent = $themes->get($parentSlug);
        $name = (string) ($this->option('name') ?: Str::headline($slug));
        $class = 'Themes\\'.Str::studly($slug).'\\Theme';

        $files->ensureDirectoryExists($path.'/views');
        $files->ensureDirectoryExists($path.'/config');
        $files->ensureDirectoryExists($path.'/assets/css');

        $manifest = [
            'name' => $name,
            'slug' => $slug,
            'parent' => $parentSlug,
            'version' => '1.0.0',
            'requires' => 'pine/commerce ^1.0',
            'description' => "Child theme of {$parent->name}.",
            'class' => $class,
            'supports' => $parent->supported(),
            'editor_css' => ($parent->manifest()['editor_css'] ?? []) ?: [],
            'settings' => new \stdClass,
        ];
        $files->put($path.'/theme.json', json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES).PHP_EOL);

        $namespace = Str::beforeLast($class, '\\');
        $files->put($path.'/Theme.php', <<<PHP
<?php

namespace {$namespace};

use Pine\\Commerce\\Theme\\ThemeDefinition;

/**
 * Optional hooks of the "{$name}" theme (docs/ARCHITECTURE.md §7.5). Delete this file and the "class" key in
 * theme.json if the theme needs none.
 */
class Theme extends ThemeDefinition
{
    public function boot(): void
    {
        // View composers, Blade directives, shortcodes (Pine\\Commerce\\Commerce::shortcode) …
    }

    public function bodyClass(string \$key, string \$classes, array \$data): string
    {
        return \$classes;
    }
}

PHP);
        $files->put($path.'/config/.gitkeep', '');
        $files->put($path.'/assets/css/theme.css', <<<CSS
/*
 * {$name} – brand overrides, loaded after the parent theme's stylesheet.
 * Colours and fonts are CSS custom properties (Admin › Settings › Theme sets the main ones), e.g.:
 *
 * :root {
 *     --color-primary: #0f766e;
 *     --radius: 14px;
 * }
 */

CSS);
        $files->put($path.'/views/README.md', $this->readme($name, $parentSlug, $parent->viewsPath(), $slug));

        if ($this->option('copy')) {
            if (is_dir($parent->viewsPath())) {
                $files->copyDirectory($parent->viewsPath(), $path.'/views');
            }
            if (is_dir($parent->assetsPath())) {
                $files->copyDirectory($parent->assetsPath(), $path.'/assets');
            }
        }

        $themes->clearCache();
        $this->components->info("Theme [{$slug}] created in themes/{$slug} (parent: {$parentSlug}).");
        $this->line('  Next: php artisan commerce:theme:publish '.$slug.'   then set COMMERCE_THEME='.$slug.' (or preview it: /?preview_theme='.$slug.' while signed in as staff).');

        return self::SUCCESS;
    }

    protected function readme(string $name, string $parent, string $parentViews, string $slug): string
    {
        // Path to show in the cp example: vendor/pine/commerce/… in a client project (the package may be a symlink),
        // relative to the project root when the parent lives in it, else absolute.
        $shown = rtrim($parentViews, '/');
        if (is_dir($vendor = base_path('vendor/pine/commerce/resources/themes/'.$parent.'/views'))
            && realpath($vendor) === realpath($parentViews)) {
            $shown = 'vendor/pine/commerce/resources/themes/'.$parent.'/views';
        } elseif (str_starts_with($shown, base_path().'/')) {
            $shown = substr($shown, strlen(base_path()) + 1);
        }
        $lines = [];
        $files = is_dir($parentViews) ? (new Filesystem)->allFiles($parentViews) : [];
        foreach ($files as $file) {
            if (str_ends_with($file->getFilename(), '.blade.php')) {
                $lines[] = '- `'.str_replace('\\', '/', $file->getRelativePathname()).'`';
            }
        }
        sort($lines);
        $contract = implode("\n", array_map(fn ($v) => '- `'.$v.'`', ThemeContract::views()));

        return <<<MD
# {$name} – views

This theme inherits every view from its parent **{$parent}** (and from `default`). To change one, copy the parent's
file to the **same relative path** in this directory and edit the copy – nothing else is needed:

    mkdir -p themes/{$slug}/views/partials
    cp {$shown}/partials/header.blade.php themes/{$slug}/views/partials/header.blade.php

Views resolve in this order: `resources/views` (client one-off overrides) → this theme → its parent(s) → `default`.
Prefer small overrides (a partial) over copying a whole page, so parent updates keep reaching you. Styling alone
usually needs no view at all: put CSS in `assets/css/theme.css` and run `php artisan commerce:theme:publish`.

## Views core renders (theme contract, docs/ARCHITECTURE.md §8)

{$contract}

## Every view of the parent theme you can override

MD.implode("\n", $lines).PHP_EOL;
    }
}
