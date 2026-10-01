<?php

namespace Pine\Commerce\View\Components;

use Pine\Commerce\Models\Menu;
use Pine\Commerce\Models\MenuItem;
use Illuminate\View\Component;

/**
 * Shared plumbing for the storefront navigation components.
 *
 * Menus are admin-managed (menus + menu_items tables); trees are cached as arrays per menu version.
 * Every component normalises its tree to plain arrays:
 *
 *   ['label' => string, 'url' => ?string, 'badge' => ?string, 'icon' => ?string (image path/URL),
 *    'class' => ?string, 'new_tab' => bool, 'children' => array]
 *
 * and falls back to the active theme's config menus.fallbacks.{key} when a location has no items (a client theme
 * can ship its legacy site's hard-coded navigation there; the default theme has none).
 * Labels and URLs may use store-setting tokens such as {store.phone} (see expandTokens()).
 */
abstract class MenuComponent extends Component
{
    /**
     * Hosts the legacy (WordPress) content and menu links point at; rewritten to this site. Config
     * commerce.legacy_hosts (lower-case host names; empty for a store that never ran on WordPress).
     *
     * @return list<string>
     */
    public static function legacyHosts(): array
    {
        return array_values(array_filter(array_map(fn ($h) => strtolower(trim((string) $h)), (array) config('commerce.legacy_hosts', []))));
    }

    /** Fallback items of the active theme (theme config menus.fallbacks.{key}); [] when the theme has none. */
    protected static function fallback(string $key): array
    {
        $items = theme_config('menus.fallbacks.'.$key, []);

        return is_array($items) ? array_values($items) : [];
    }

    /** Per-request snapshot of every menu: location => ['name' => ..., 'stamp' => cache version]. */
    protected static ?array $menus = null;

    /**
     * Normalised tree for the first location that has items, else the defaults.
     *
     * @param  string[]  $locations
     */
    protected static function load(array $locations, array $defaults): array
    {
        foreach ($locations as $location) {
            $items = static::tree($location);
            if ($items) {
                return $items;
            }
        }

        return static::expandTokens($defaults);
    }

    /**
     * Public entry point for themes (helper menu_tree()): normalised tree of the first location that has items,
     * else $fallback.
     *
     * @param  string|string[]  $locations
     */
    public static function treeFor(string|array $locations, array $fallback = []): array
    {
        return static::load((array) $locations, $fallback);
    }

    /** Menu name of a location (e.g. a footer column heading), or null. */
    public static function nameFor(string $location): ?string
    {
        return static::menuName($location);
    }

    /** Per-request copy of all menu trees (location => normalised items). */
    protected static ?array $trees = null;

    /** Forget the per-process menu memo (long-running processes and tests that switch database/theme). */
    public static function flush(): void
    {
        static::$menus = null;
        static::$trees = null;
    }

    /**
     * Menu tree for a location as plain arrays. All menus are cached together, keyed by a version stamp
     * (item counts + latest updates), so admin edits show immediately. Arrays rather than models:
     * Laravel 13's cache refuses to unserialize objects (config/cache.php serializable_classes).
     */
    protected static function tree(string $location): array
    {
        if (static::$trees === null) {
            try {
                $menus = static::menus();
                $version = md5(json_encode($menus));
                static::$trees = $menus ? cache()->remember('nav.trees.'.$version, 86400, function () use ($menus) {
                    $items = MenuItem::orderBy('sort_order')->orderBy('id')
                        ->get(['id', 'menu_id', 'parent_id', 'label', 'url', 'badge', 'icon', 'css_class', 'open_in_new_tab', 'sort_order'])
                        ->groupBy('menu_id');
                    $trees = [];
                    foreach ($menus as $location => $menu) {
                        $flat = $items->get($menu['id'], collect());
                        $trees[$location] = static::normalise($flat->groupBy(fn ($i) => (int) $i->parent_id), 0);
                    }

                    return $trees;
                }) : [];
            } catch (\Throwable $e) {
                report($e);
                static::$trees = [];
            }
        }

        // tokens are expanded per request (after the cache), so a changed store setting shows at once
        return static::expandTokens(static::$trees[$location] ?? []);
    }

    /**
     * Menu labels and URLs may contain store-setting tokens, e.g. a label "Need help? Call {store.phone}" with the URL
     * "tel:{store.phone}", so the item follows Settings › Store instead of a number typed into the menu. Only
     * store.* settings are available (never payment/mail secrets); an unknown or empty setting expands to ''.
     * In a tel: URL the expanded value keeps only digits and "+".
     */
    public static function expandTokens(array $items): array
    {
        foreach ($items as $i => $item) {
            foreach (['label', 'url'] as $field) {
                $value = $item[$field] ?? null;
                if (is_string($value) && str_contains($value, '{store.')) {
                    $items[$i][$field] = static::expandToken($value, $field === 'url' && preg_match('#^\s*tel:#i', $value) === 1);
                }
            }
            if (! empty($item['children'])) {
                $items[$i]['children'] = static::expandTokens($item['children']);
            }
        }

        return $items;
    }

    /** Replace {store.key} tokens in one string (see expandTokens()). */
    public static function expandToken(string $value, bool $tel = false): string
    {
        return preg_replace_callback('/\{(store\.[a-z0-9_]+)\}/i', function (array $m) use ($tel) {
            $setting = trim((string) setting(strtolower($m[1]), ''));

            return $tel ? preg_replace('/[^0-9+]/', '', $setting) : $setting;
        }, $value);
    }

    protected static function menus(): array
    {
        if (static::$menus !== null) {
            return static::$menus;
        }
        try {
            $rows = Menu::query()->get(['id', 'location', 'name', 'updated_at']);
            $stats = MenuItem::query()->groupBy('menu_id')
                ->selectRaw('menu_id, count(*) as c, max(updated_at) as u, max(id) as m')->get()->keyBy('menu_id');
            static::$menus = [];
            foreach ($rows as $row) {
                $st = $stats[$row->id] ?? null;
                static::$menus[$row->location] = [
                    'id' => $row->id,
                    'name' => $row->name,
                    'stamp' => implode('|', [$row->id, $row->updated_at, $st?->c, $st?->u, $st?->m]),
                ];
            }
        } catch (\Throwable $e) {
            report($e);
            static::$menus = [];
        }

        return static::$menus;
    }

    /** Menu name for a location (used as a column heading), or null. */
    protected static function menuName(string $location): ?string
    {
        return static::menus()[$location]['name'] ?? null;
    }

    /** @param  \Illuminate\Support\Collection  $byParent  items grouped by parent id (0 = root) */
    public static function normalise($byParent, int $parentId): array
    {
        $out = [];
        foreach ($byParent->get($parentId, collect()) as $item) {
            $label = trim(html_entity_decode(strip_tags((string) $item->label), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
            $icon = $item->icon;
            // legacy menu labels sometimes embed the tile image
            if (! $icon && preg_match('/<img[^>]+src=["\']([^"\']+)/i', (string) $item->label, $m)) {
                $icon = $m[1];
            }
            $out[] = [
                'label' => $label,
                'url' => $item->url,
                'badge' => $item->badge,
                'icon' => $icon,
                'class' => $item->css_class,
                'new_tab' => (bool) $item->open_in_new_tab,
                'children' => static::normalise($byParent, (int) $item->id),
            ];
        }

        return $out;
    }

    /** Turn a stored menu URL into a link on this site (legacy absolute URLs become relative). */
    public static function href(?string $url): ?string
    {
        if ($url === null || trim($url) === '') {
            return null;
        }
        $url = trim(html_entity_decode($url, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        if (preg_match('#^(mailto:|tel:|\#)#i', $url)) {
            return $url;
        }
        if (preg_match('#^(?:https?:)?//([^/]+)(/.*)?$#i', $url, $m)) {
            if (! in_array(strtolower($m[1]), self::legacyHosts(), true)) {
                return $url; // genuinely external
            }
            $url = $m[2] ?? '/';
        }
        if (! str_starts_with($url, '/')) {
            $url = '/'.$url;
        }
        [$path, $query] = array_pad(explode('?', $url, 2), 2, null);
        $trimmed = trim($path, '/');
        if ($trimmed === '') {
            return url('/').($query !== null ? '/?'.$query : '');
        }

        return url($trimmed).($query !== null ? '?'.$query : '');
    }

    /** Public URL for a menu image (upload path, /wp-content/uploads URL or absolute URL). */
    public static function image(?string $path): ?string
    {
        if (! $path) {
            return null;
        }
        if (preg_match('#/wp-content/uploads/(.+)$#', $path, $m)) {
            return media_url('uploads/'.$m[1]);
        }
        if (preg_match('#^(https?:)?//#', $path) || str_starts_with($path, '/')) {
            return $path;
        }

        return media_url(str_starts_with($path, 'uploads/') ? $path : 'uploads/'.ltrim($path, '/'));
    }

    /** Is this link the current page (for aria-current)? */
    public static function isCurrent(?string $href): bool
    {
        if (! $href || ! str_starts_with($href, url('/'))) {
            return false;
        }

        return rtrim(parse_url($href, PHP_URL_PATH) ?? '/', '/') === rtrim('/'.request()->path(), '/')
            && parse_url($href, PHP_URL_QUERY) === null;
    }
}
