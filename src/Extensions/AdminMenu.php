<?php

namespace Pine\Commerce\Extensions;

/**
 * Extra back-office sidebar entries from client code / themes (Commerce::adminMenu()), merged into the core menu by
 * Pine\Commerce\View\Components\Admin\Sidebar. Entries can also come from config commerce.admin.menu (same keys).
 *
 *   Commerce::adminMenu()->add('Trade accounts', 'briefcase', 'admin.trade.index', ['admin.trade.*'], after: 'admin.customers.index');
 *   Commerce::adminMenu()->child('admin.products.index', 'Warranty claims', 'admin.warranty.index', ['admin.warranty.*']);
 *   Commerce::adminMenu()->remove('admin.reports.index');
 *
 * Entry shape: label, icon (admin icon name), route (route NAME), active (route-name patterns that highlight it,
 * default the route and route.*), after (route of the top-level item to insert after; null = at the end), children (list of entries), feature (feature switch that must be on), admin (administrators only), count (int or
 * closure: the badge number) + countLabel.
 */
class AdminMenu
{
    /** @var list<array> */
    protected array $items = [];

    /** @var array<string, list<array>> parent route => children */
    protected array $children = [];

    /** @var array<string,true> */
    protected array $removed = [];

    /**
     * @param  list<string>  $active
     * @param  list<array>  $children
     */
    public function add(string $label, ?string $icon, string $route, array $active = [], ?string $after = null,
        array $children = [], ?string $feature = null, bool $admin = false, int|\Closure|null $count = null, ?string $countLabel = null): static
    {
        $this->items[] = static::entry(compact('label', 'icon', 'route', 'active', 'after', 'children', 'feature', 'admin', 'count', 'countLabel'));

        return $this;
    }

    /** A sub-item under an existing top-level entry (core or client), shown while that section is open. */
    public function child(string $parentRoute, string $label, string $route, array $active = [], ?string $feature = null, bool $admin = false): static
    {
        $this->children[$parentRoute][] = static::entry(compact('label', 'route', 'active', 'feature', 'admin') + ['icon' => null]);

        return $this;
    }

    /** Hide a core (or client) entry, top-level or child, by its route name. */
    public function remove(string $route): static
    {
        $this->removed[$route] = true;

        return $this;
    }

    /** @return list<array> top-level entries: registered + config commerce.admin.menu */
    public function items(): array
    {
        $config = array_values(array_filter(array_map(
            fn ($e) => is_array($e) && ! empty($e['route']) && ! empty($e['label']) ? static::entry($e) : null,
            (array) config('commerce.admin.menu', [])
        )));

        return array_merge($config, $this->items);
    }

    /** @return array<string, list<array>> */
    public function children(): array
    {
        return $this->children;
    }

    public function isRemoved(string $route): bool
    {
        return isset($this->removed[$route]);
    }

    /** Normalise an entry array. */
    public static function entry(array $e): array
    {
        $route = (string) $e['route'];

        return [
            'label' => (string) $e['label'],
            'icon' => $e['icon'] ?? null,
            'route' => $route,
            'active' => array_values((array) ($e['active'] ?? [])) ?: [$route, $route.'.*'],
            'after' => $e['after'] ?? null,
            'children' => array_map(fn ($c) => static::entry($c + ['icon' => null]), array_values((array) ($e['children'] ?? []))),
            'feature' => $e['feature'] ?? null,
            'admin' => (bool) ($e['admin'] ?? false),
            'count' => $e['count'] ?? null,
            'countLabel' => $e['countLabel'] ?? null,
        ];
    }
}
