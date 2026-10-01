<?php

namespace Pine\Commerce\Import\Steps;

use Illuminate\Support\Facades\DB;
use Pine\Commerce\Import\Contracts\MenuProvider;
use Pine\Commerce\Import\Data\MenuNode;
use Pine\Commerce\Import\Source\WordPressSource;
use Pine\Commerce\Import\Support\Formatter;

/**
 * Menus.
 *  - WordPress nav menus -> local menu locations: `commerce-import.menus.by_term_id` (nav_menu term id => location),
 *    then the theme's nav_menu_locations (theme_mod) mapped by `menus.by_location` (WP location => local location;
 *    when both maps are empty every WP location keeps its name), then – with `menus.import_unassigned` – every other
 *    nav menu as location "wp-{slug}" so nothing is lost. Labels, hierarchy, relative URLs, image tiles kept.
 *  - MenuProvider adapters add menus that are not nav menus (e.g. a client adapter rebuilding an Elementor mega menu
 *    from the rendered header); each MenuTree replaces the menu at its location.
 *
 * Image tiles/promos store the image's public-disk path in menu_items.icon.
 */
class MenusStep extends AbstractStep
{
    public function key(): string
    {
        return 'menus';
    }

    public function section(): string
    {
        return 'menus';
    }

    public function after(): array
    {
        return ['content.pages', 'content.posts', 'catalog.products'];
    }

    private int $items = 0;

    /** @return array<int,string> nav_menu term id => local location, in import order */
    private function wordPressMap(): array
    {
        $map = [];
        foreach ((array) $this->ctx->config('menus.by_term_id', []) as $termId => $location) {
            $map[(int) $termId] = (string) $location;
        }
        $byLocation = (array) $this->ctx->config('menus.by_location', []);
        foreach ($this->ctx->site->menuLocations as $wpLocation => $termId) {
            $local = $byLocation[$wpLocation] ?? (! $byLocation && ! $map ? $wpLocation : null);
            if ($local !== null && ! isset($map[$termId]) && ! in_array($local, $map, true)) {
                $map[$termId] = (string) $local;
            }
        }
        if ($this->ctx->config('menus.import_unassigned', true)) {
            foreach ($this->wp->terms('nav_menu') as $termId => $menu) {
                $map[(int) $termId] ??= 'wp-'.urldecode($menu->slug);
            }
        }

        return $map;
    }

    protected function clear(): void
    {
        $locations = array_values($this->wordPressMap());
        foreach ($this->ctx->adapters->providers(MenuProvider::class) as $provider) {
            foreach ($provider->menus($this->ctx) as $tree) {
                $locations[] = $tree->location;
            }
        }
        DB::table('menus')->whereIn('location', array_unique($locations))->delete();
    }

    protected function import(): void
    {
        $map = $this->wordPressMap();
        $this->wordPressMenus($map);
        $wpItems = $this->items;

        $this->items = 0;
        $trees = 0;
        $labels = [];
        foreach ($this->ctx->adapters->providers(MenuProvider::class) as $provider) {
            foreach ($provider->menus($this->ctx) as $tree) {
                $menuId = $this->menu($tree->location, $tree->name);
                $this->writeNodes($menuId, null, $tree->items);
                $trees++;
                $labels[] = $tree->location;
            }
        }
        foreach (DB::table('menus')->pluck('location') as $location) {
            cache()->forget('menu.'.$location);
        }

        $this->ctx->count('Menus (WP nav menus)', count($map), count($map), "$wpItems items");
        if ($trees) {
            $this->ctx->count('Menus (adapters)', '—', $trees, "$this->items items: ".implode(', ', $labels));
        }
    }

    /** @param list<MenuNode> $nodes */
    private function writeNodes(int $menuId, ?int $parentId, array $nodes): void
    {
        foreach (array_values($nodes) as $i => $node) {
            $id = $this->item($menuId, $parentId, $node->label, $node->url, $node->sortOrder ?? $i, [
                'badge' => $node->badge, 'icon' => $node->icon, 'css_class' => $node->cssClass, 'open_in_new_tab' => $node->newTab,
            ]);
            if ($node->children) {
                $this->writeNodes($menuId, $id, $node->children);
            }
        }
    }

    // ------------------------------------------------------------------ WordPress nav menus

    private function wordPressMenus(array $map): void
    {
        $menus = $this->wp->terms('nav_menu');
        $categoryPaths = DB::table('categories')->whereNotNull('wp_id')->pluck('path', 'wp_id')->all();
        $categoryNames = DB::table('categories')->whereNotNull('wp_id')->pluck('name', 'wp_id')->all();
        $pages = DB::table('pages')->whereNotNull('wp_id')->get(['wp_id', 'path', 'title'])->keyBy('wp_id');
        $posts = DB::table('posts')->whereNotNull('wp_id')->get(['wp_id', 'slug', 'title'])->keyBy('wp_id');

        foreach ($map as $termId => $location) {
            $menu = $menus[$termId] ?? null;
            if (! $menu) {
                $this->ctx->warn("WordPress menu $termId ($location) not found");

                continue;
            }
            $menuId = $this->menu($location, Formatter::decode($menu->name));

            $items = $this->wp->table('posts as p')
                ->join('term_relationships as tr', 'tr.object_id', '=', 'p.ID')
                ->where('tr.term_taxonomy_id', $menu->term_taxonomy_id)
                ->where('p.post_type', 'nav_menu_item')->where('p.post_status', 'publish')
                ->orderBy('p.menu_order')->get(['p.ID', 'p.post_title', 'p.menu_order']);
            $meta = $this->wp->postMeta($items->pluck('ID')->all());
            $products = $this->productUrls($items->map(fn ($i) => ($meta[$i->ID]['_menu_item_object'] ?? '') === 'product' ? (int) ($meta[$i->ID]['_menu_item_object_id'] ?? 0) : 0)->filter()->all());

            $created = [];
            $pending = $items->all();
            // insert parents before children (menu_order already places parents first)
            for ($pass = 0; $pending && $pass < 5; $pass++) {
                foreach ($pending as $k => $item) {
                    $m = $meta[$item->ID] ?? [];
                    $parentWp = (int) ($m['_menu_item_menu_item_parent'] ?? 0);
                    if ($parentWp && ! isset($created[$parentWp])) {
                        continue;
                    }
                    [$label, $icon] = $this->labelAndImage((string) $item->post_title);
                    $type = $m['_menu_item_type'] ?? 'custom';
                    $object = $m['_menu_item_object'] ?? '';
                    $objectId = (int) ($m['_menu_item_object_id'] ?? 0);
                    $url = null;
                    if ($type === 'taxonomy' && $object === 'product_cat') {
                        $url = isset($categoryPaths[$objectId]) ? '/'.$categoryPaths[$objectId].'/' : null;
                        $label = $label !== '' ? $label : ($categoryNames[$objectId] ?? '');
                    } elseif ($type === 'post_type' && $object === 'page' && ($page = $pages->get($objectId))) {
                        $url = $page->path === '' ? '/' : '/'.$page->path.'/';
                        $label = $label !== '' ? $label : $page->title;
                    } elseif ($type === 'post_type' && $object === 'post' && ($post = $posts->get($objectId))) {
                        $url = '/blog/'.$post->slug.'/';
                        $label = $label !== '' ? $label : $post->title;
                    } elseif ($type === 'post_type' && $object === 'product' && isset($products[$objectId])) {
                        [$url, $name] = $products[$objectId];
                        $label = $label !== '' ? $label : $name;
                    } else {
                        $url = Formatter::relativeUrl($m['_menu_item_url'] ?? null);
                        $url = $url ? Formatter::withTrailingSlash($url) : null;
                    }
                    if ($label === '') {
                        unset($pending[$k]);

                        continue;
                    }
                    $classes = WordPressSource::unserialize($m['_menu_item_classes'] ?? '');
                    $created[$item->ID] = $this->item($menuId, $parentWp ? $created[$parentWp] : null, $label, $url, (int) $item->menu_order, [
                        'icon' => $icon,
                        'css_class' => is_array($classes) ? (trim(implode(' ', array_filter($classes))) ?: null) : null,
                        'open_in_new_tab' => ($m['_menu_item_target'] ?? '') === '_blank',
                    ]);
                    unset($pending[$k]);
                }
            }
        }
    }

    /** @return array<int,array{0:string,1:string}> wp product id => [url, name] */
    private function productUrls(array $wpIds): array
    {
        if (! $wpIds) {
            return [];
        }
        $paths = DB::table('categories')->pluck('path', 'id')->all();
        $out = [];
        foreach (DB::table('products')->whereIn('wp_id', $wpIds)->get(['wp_id', 'slug', 'name', 'primary_category_id']) as $p) {
            $out[(int) $p->wp_id] = ['/'.(isset($paths[$p->primary_category_id]) ? $paths[$p->primary_category_id].'/' : 'product/').$p->slug.'/', $p->name];
        }

        return $out;
    }

    /** Legacy Impreza menu titles embed an image + "w-text" label: return [label, image path]. */
    private function labelAndImage(string $title): array
    {
        $icon = null;
        if (preg_match('/<img[^>]+src="([^"]+)"/i', $title, $m)) {
            $icon = Formatter::uploadPath($m[1]);
        }
        if (preg_match('#<div class="w-text">(.*?)</div>#s', $title, $m)) {
            $title = $m[1];
        }

        return [Formatter::text($title), $icon];
    }

    // ------------------------------------------------------------------ helpers

    /** Create/reset a menu for a location and return its id (items are rebuilt on every run). */
    private function menu(string $location, string $name): int
    {
        $id = DB::table('menus')->where('location', $location)->value('id');
        if ($id) {
            DB::table('menus')->where('id', $id)->update(['name' => $name, 'updated_at' => $this->now()]);
            DB::table('menu_items')->where('menu_id', $id)->whereNotNull('parent_id')->delete();
            DB::table('menu_items')->where('menu_id', $id)->delete();

            return $id;
        }

        return DB::table('menus')->insertGetId(['name' => $name, 'location' => $location, 'created_at' => $this->now(), 'updated_at' => $this->now()]);
    }

    private function item(int $menuId, ?int $parentId, string $label, ?string $url, int $order, array $extra = []): int
    {
        $this->items++;

        return DB::table('menu_items')->insertGetId([
            'menu_id' => $menuId,
            'parent_id' => $parentId,
            'label' => mb_substr(trim($label), 0, 250),
            'url' => $url,
            'badge' => $extra['badge'] ?? null,
            'icon' => $extra['icon'] ?? null,
            'css_class' => $extra['css_class'] ?? null,
            'open_in_new_tab' => $extra['open_in_new_tab'] ?? false,
            'sort_order' => $order,
            'created_at' => $this->now(),
            'updated_at' => $this->now(),
        ]);
    }
}
