<?php

namespace Pine\Commerce\Theme;

/**
 * One installed theme (a directory with a theme.json). Immutable value object built by ThemeManager from the manifest.
 *
 *   theme()->slug / ->name / ->version / ->path
 *   theme()->viewsPath(), ->assetsPath(), ->publicPath() ("assets" | "themes/{slug}"), ->publicDir()
 *   theme()->supports('side-cart'), ->config('menus.fallbacks.mega'), ->settingsSchema(), ->editorCss()
 *
 * config() and settingsSchema() are resolved over this theme's own chain (this theme, its parents, then "default"):
 * a child overrides its parent per top-level key.
 */
class Theme
{
    /** @param array<string,mixed> $manifest theme.json contents (+ 'config' => [file => array]) */
    public function __construct(
        public readonly string $slug,
        public readonly string $path,
        protected array $manifest,
        protected ThemeManager $manager,
    ) {
        $this->name = (string) ($manifest['name'] ?? $slug);
        $this->version = (string) ($manifest['version'] ?? '0.0.0');
    }

    public readonly string $name;

    public readonly string $version;

    public function manifest(): array
    {
        return $this->manifest;
    }

    public function parent(): ?string
    {
        $parent = $this->manifest['parent'] ?? null;

        return is_string($parent) && $parent !== '' ? $parent : null;
    }

    public function description(): string
    {
        return (string) ($this->manifest['description'] ?? '');
    }

    /** Package-shipped theme (the package's resources/themes/*) rather than an app theme (themes/*). */
    public function isCore(): bool
    {
        return (bool) ($this->manifest['core'] ?? false);
    }

    public function viewsPath(): string
    {
        return $this->path.'/views';
    }

    public function assetsPath(): string
    {
        return $this->path.'/assets';
    }

    /** Where the assets are published (copied) to, relative to public/ – theme.json "public_path", default themes/{slug}. */
    public function publicPath(): string
    {
        $path = trim((string) ($this->manifest['public_path'] ?? ''), '/');

        return $path !== '' ? $path : 'themes/'.$this->slug;
    }

    public function publicDir(): string
    {
        return public_path($this->publicPath());
    }

    public function supports(string $feature): bool
    {
        return in_array($feature, $this->supported(), true);
    }

    /** @return list<string> */
    public function supported(): array
    {
        return array_values(array_map('strval', (array) ($this->manifest['supports'] ?? [])));
    }

    /** @return list<Theme> this theme followed by its ancestors ("default" last). */
    public function lineage(): array
    {
        return $this->manager->chain($this->slug);
    }

    /** Theme config value ('menus.fallbacks.mega' = key fallbacks.mega of config/menus.php), merged over the lineage. */
    public function config(string $key, $default = null)
    {
        [$file, $rest] = array_pad(explode('.', $key, 2), 2, null);
        $merged = null;
        foreach (array_reverse($this->lineage()) as $theme) {
            $data = $theme->manifest['config'][$file] ?? null;
            if (is_array($data)) {
                $merged = array_replace($merged ?? [], $data);
            }
        }
        if ($merged === null) {
            return $default;
        }

        return $rest === null ? $merged : data_get($merged, $rest, $default);
    }

    /** Own config files only (no lineage merge). */
    public function ownConfig(): array
    {
        return (array) ($this->manifest['config'] ?? []);
    }

    /**
     * Theme settings schema merged over the lineage: key => {type, default, label, help, options, group}.
     *
     * @return array<string, array<string,mixed>>
     */
    public function settingsSchema(): array
    {
        $schema = [];
        foreach (array_reverse($this->lineage()) as $theme) {
            foreach ((array) ($theme->manifest['settings'] ?? []) as $key => $field) {
                if (is_array($field)) {
                    $schema[$key] = array_replace($schema[$key] ?? [], $field);
                }
            }
        }

        return $schema;
    }

    /** @return list<string> theme.json "editor_css" as asset URLs (admin rich-text editors). */
    public function editorCss(): array
    {
        return array_map(fn ($path) => $this->manager->asset((string) $path, $this), (array) ($this->manifest['editor_css'] ?? []));
    }

    /**
     * Root-relative, versioned URLs of the published editor_css files ("/assets/css/site.css?v=…") – the admin rich-text
     * editors load them so content is edited in the storefront's styles. Unpublished files are skipped.
     *
     * @param  list<string>  $extra  more theme asset paths (e.g. a page's legacy Elementor CSS)
     * @return list<string>
     */
    public function editorCssUrls(array $extra = []): array
    {
        $urls = [];
        foreach (array_merge((array) ($this->manifest['editor_css'] ?? []), $extra) as $path) {
            $relative = $this->manager->publishedAsset((string) $path, $this);
            if ($relative !== null) {
                $urls[] = '/'.$relative.'?v='.filemtime(public_path($relative));
            }
        }

        return $urls;
    }

    public function definition(): ?ThemeDefinition
    {
        return $this->manager->definition($this);
    }

    /** Settings key a theme setting is stored under: theme.{slug}.{key}. */
    public function settingKey(string $key): string
    {
        return 'theme.'.$this->slug.'.'.$key;
    }
}
