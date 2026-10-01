<?php

namespace Pine\Commerce\Http\Controllers\Admin;

use Pine\Commerce\Http\Controllers\Admin\Concerns\AdminIndex;
use Pine\Commerce\Http\Controllers\Controller;
use Pine\Commerce\Http\Requests\Admin\Content\MenuRequest;
use Pine\Commerce\Http\Requests\Admin\Content\MenuTreeRequest;
use Pine\Commerce\Models\Category;
use Pine\Commerce\Models\Menu;
use Pine\Commerce\Models\MenuItem;
use Pine\Commerce\Models\Page;
use Pine\Commerce\Models\Post;
use Pine\Commerce\Models\Product;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

/**
 * Navigation menus. The storefront reads menus by location (Pine\Commerce\View\Components\MenuComponent and friends) and caches
 * them under a version stamp built from the menu/items timestamps, so a save shows on the site immediately.
 * edit = drag-and-drop builder; update = whole tree in one POST (see MenuTreeRequest).
 */
class MenuController extends Controller
{
    use AdminIndex;

    /** Locations the storefront renders (first match wins where a fallback is listed). */
    public const LOCATIONS = [
        'mega' => ['label' => 'Header menu', 'where' => 'Main navigation across the top of every page (desktop).', 'fallback_for' => null],
        'main' => ['label' => 'Header menu (backup)', 'where' => 'Used only when “Header menu” (mega) has no items.', 'fallback_for' => 'mega'],
        'mobile_nav' => ['label' => 'Mobile menu', 'where' => 'Slide-out menu on phones and tablets.', 'fallback_for' => null],
        'mobile' => ['label' => 'Mobile menu (backup)', 'where' => 'Used only when “Mobile menu” (mobile_nav) has no items.', 'fallback_for' => 'mobile_nav'],
        'footer_shop' => ['label' => 'Footer – first column', 'where' => 'First link column in the footer. The menu name is the column heading.', 'fallback_for' => null],
        'footer_company' => ['label' => 'Footer – second column', 'where' => 'Second link column in the footer. The menu name is the column heading.', 'fallback_for' => null],
        'footer_legal' => ['label' => 'Footer – legal links', 'where' => 'Small links next to the copyright line.', 'fallback_for' => null],
    ];

    /**
     * Menu locations offered in the admin: the core ones plus client/theme locations (Commerce::menuLocation()).
     *
     * @return array<string, array{label:string, where:string, fallback_for:?string}>
     */
    public static function locations(): array
    {
        $locations = self::LOCATIONS;
        foreach (app(\Pine\Commerce\Extensions\ExtensionRegistry::class)->menuLocations() as $key => $label) {
            $locations[$key] ??= ['label' => $label, 'where' => '', 'fallback_for' => null];
        }

        return $locations;
    }

    public function index(): View
    {
        $menus = Menu::query()->withCount('items')->orderBy('name')->get();
        $counts = $menus->pluck('items_count', 'location');

        $rows = $menus->map(function (Menu $menu) use ($counts) {
            $location = static::locations()[$menu->location] ?? null;
            $inUse = $location !== null && $menu->items_count > 0
                && (! $location['fallback_for'] || (int) ($counts[$location['fallback_for']] ?? 0) === 0);

            return ['menu' => $menu, 'location' => $location, 'inUse' => $inUse];
        })->sortBy(fn ($r) => [$r['inUse'] ? 0 : 1, array_search($r['menu']->location, array_keys(static::locations()), true) === false ? 99 : array_search($r['menu']->location, array_keys(static::locations()), true)])->values();

        return view('commerce::admin.menus.index', [
            'rows' => $rows,
            'missing' => array_diff_key(static::locations(), $counts->all()),
        ]);
    }

    public function create(Request $request): View
    {
        $location = $request->query('location');

        return view('commerce::admin.menus.create', [
            'menu' => new Menu(['location' => is_string($location) && isset(static::locations()[$location]) ? $location : '']),
            'locations' => $this->locationOptions(),
        ]);
    }

    public function store(MenuRequest $request): RedirectResponse
    {
        $menu = Menu::create($request->menuData());

        return redirect()->route('admin.menus.edit', $menu)->with('success', "Menu “{$menu->name}” created. Add its links below.");
    }

    public function edit(Menu $menu): View
    {
        $items = $menu->items()->get(['id', 'parent_id', 'label', 'url', 'badge', 'icon', 'css_class', 'open_in_new_tab', 'sort_order']);

        return view('commerce::admin.menus.edit', [
            'menu' => $menu,
            'tree' => static::tree($items),
            'location' => static::locations()[$menu->location] ?? null,
            'locations' => $this->locationOptions($menu),
        ]);
    }

    public function update(MenuTreeRequest $request, Menu $menu): RedirectResponse
    {
        $tree = $request->tree();
        $stats = ['created' => 0, 'updated' => 0, 'deleted' => 0];

        DB::transaction(function () use ($menu, $tree, $request, &$stats) {
            $menu->fill($request->menuData());
            $existing = $menu->items()->get()->keyBy('id');
            $kept = [];

            $save = function (array $nodes, ?int $parentId) use (&$save, $menu, $existing, &$kept, &$stats) {
                foreach (array_values($nodes) as $position => $node) {
                    $attributes = [
                        'parent_id' => $parentId,
                        'label' => $node['label'],
                        'url' => $node['url'],
                        'badge' => $node['badge'],
                        'icon' => $node['icon'],
                        'css_class' => $node['css_class'],
                        'open_in_new_tab' => $node['open_in_new_tab'],
                        'sort_order' => $position,
                    ];
                    $item = $node['id'] ? $existing->get($node['id']) : null;
                    if ($item) {
                        $item->fill($attributes);
                        if ($item->isDirty()) {
                            $item->save();
                            $stats['updated']++;
                        }
                    } else {
                        $item = $menu->items()->create($attributes);
                        $stats['created']++;
                    }
                    $kept[] = $item->id;
                    $save($node['children'], $item->id);
                }
            };
            $save($tree, null);

            $removed = $existing->keys()->diff($kept)->values();
            if ($removed->isNotEmpty()) {
                // children first is not needed (FK cascades), but every kept item has already been re-parented above
                $stats['deleted'] = MenuItem::whereIn('id', $removed)->delete();
            }

            // New version stamp for the storefront's menu cache (MenuComponent keys it on the menu's updated_at, which has
            // one-second resolution) – always move it forward, even for two saves within the same second.
            $previous = $menu->getOriginal('updated_at');
            $now = now()->startOfSecond();
            $menu->updated_at = $previous && $now->lte($previous) ? \Illuminate\Support\Carbon::parse($previous)->startOfSecond()->addSecond() : $now;
            $menu->save();
        });

        $changes = array_filter($stats);
        $summary = $changes
            ? implode(', ', array_map(fn ($k, $n) => $n.' '.$k, array_keys($changes), $changes))
            : 'no link changes';

        return redirect()->route('admin.menus.edit', $menu)->with('success', "Menu saved ({$summary}). The site shows the new menu straight away.");
    }

    public function destroy(Menu $menu): RedirectResponse
    {
        $name = $menu->name;
        DB::transaction(function () use ($menu) {
            $menu->items()->whereNotNull('parent_id')->delete();
            $menu->items()->delete();
            $menu->delete();
        });

        return redirect()->route('admin.menus.index')->with('success', "Menu “{$name}” deleted.");
    }

    /**
     * GET admin/api/links?q= – link targets for URL fields: {groups: [{label, items: [{label, url, sub}]}]}.
     * URLs are site-relative with the trailing slash WordPress used ("/category/sub/").
     */
    public function links(Request $request): JsonResponse
    {
        $q = $this->searchTerm($request);
        $groups = [];
        $item = fn (string $label, string $url, ?string $sub = null) => ['label' => $label, 'url' => $url, 'sub' => $sub];
        $path = fn (?string $p) => '/'.trim((string) $p, '/').(trim((string) $p, '/') !== '' ? '/' : '');

        if ($q === '') {
            $groups[] = ['label' => 'Shop', 'items' => [
                $item('Home page', '/'), $item('Shop – all products', '/shop/'), $item('Blog', '/blog/'),
                $item('Contact us', '/contact-us/'), $item('My account', '/my-account/'), $item('Basket', '/basket/'),
            ]];
            $groups[] = ['label' => 'Categories', 'items' => Category::query()->whereNull('parent_id')->where('is_visible', true)
                ->orderBy('sort_order')->orderBy('name')->limit(12)->get(['name', 'path'])
                ->map(fn (Category $c) => $item($c->name, $path($c->path), 'Category'))->all()];
            $groups[] = ['label' => 'Pages', 'items' => Page::query()->where('status', 'published')->where('path', '!=', '')
                ->orderBy('title')->limit(12)->get(['title', 'path'])
                ->map(fn (Page $p) => $item($p->title, $path($p->path), 'Page'))->all()];

            return response()->json(['groups' => array_values(array_filter($groups, fn ($g) => $g['items']))]);
        }

        $like = $this->like($q);
        $groups[] = ['label' => 'Pages', 'items' => Page::query()
            ->where(fn ($w) => $w->where('title', 'like', $like)->orWhere('path', 'like', $this->like(trim($q, '/'))))
            ->orderByRaw("status = 'published' desc")->orderBy('title')->limit(8)->get(['title', 'path', 'status'])
            ->map(fn (Page $p) => $item($p->title, $path($p->path), $p->status === 'published' ? 'Page' : 'Draft page'))->all()];
        $groups[] = ['label' => 'Categories', 'items' => Category::query()
            ->where(fn ($w) => $w->where('name', 'like', $like)->orWhere('path', 'like', $this->like(trim($q, '/'))))
            ->orderBy('name')->limit(8)->get(['name', 'path', 'is_visible'])
            ->map(fn (Category $c) => $item($c->name, $path($c->path), $c->is_visible ? 'Category' : 'Hidden category'))->all()];
        $groups[] = ['label' => 'Products', 'items' => Product::query()->where('status', 'published')
            ->where(fn ($w) => $w->where('name', 'like', $like)->orWhere('sku', 'like', $like))
            ->with(['primaryCategory:id,path', 'categories:id,path'])
            ->orderBy('name')->limit(8)->get(['id', 'name', 'slug', 'primary_category_id'])
            ->map(fn (Product $p) => $item($p->name, $path((string) parse_url($p->url, PHP_URL_PATH)), 'Product'))->all()];
        $groups[] = ['label' => 'Blog posts', 'items' => Post::query()->where('title', 'like', $like)
            ->orderByDesc('published_at')->limit(6)->get(['title', 'slug', 'status'])
            ->map(fn (Post $p) => $item($p->title, '/blog/'.$p->slug.'/', $p->status === 'published' ? 'Post' : 'Draft post'))->all()];

        return response()->json(['groups' => array_values(array_filter($groups, fn ($g) => $g['items']))]);
    }

    /** Flat items -> nested arrays for the builder. */
    public static function tree($items): array
    {
        $byParent = $items->sortBy([['sort_order', 'asc'], ['id', 'asc']])->groupBy(fn ($i) => (int) $i->parent_id);
        $build = function (int $parentId, int $depth) use (&$build, $byParent) {
            return $byParent->get($parentId, collect())->map(fn (MenuItem $i) => [
                'id' => $i->id,
                'label' => (string) $i->label,
                'url' => $i->url,
                'badge' => $i->badge,
                'icon' => $i->icon,
                'css_class' => $i->css_class,
                'open_in_new_tab' => (bool) $i->open_in_new_tab,
                'children' => $depth < 6 ? $build($i->id, $depth + 1) : [],
            ])->values()->all();
        };

        return $build(0, 1);
    }

    protected function locationOptions(?Menu $current = null): array
    {
        $taken = Menu::query()->when($current, fn ($q) => $q->whereKeyNot($current->id))->pluck('location')->all();
        $options = [];
        foreach (static::locations() as $key => $location) {
            if (! in_array($key, $taken, true)) {
                $options[$key] = $location['label'].' ('.$key.')';
            }
        }
        if ($current && ! isset($options[$current->location])) {
            $options[$current->location] = 'Not shown on the site ('.$current->location.')';
        }

        return $options;
    }
}
