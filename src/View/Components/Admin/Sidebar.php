<?php

namespace Pine\Commerce\View\Components\Admin;

use Illuminate\Support\Facades\DB;
use Pine\Commerce\Support\Sql;
use Illuminate\Support\Facades\Route;
use Illuminate\View\Component;
use Illuminate\View\View;
use Pine\Commerce\Extensions\AdminMenu;
use Pine\Commerce\Support\Features;
use Throwable;

/**
 * Back-office navigation (<x-admin.sidebar />, rendered by the admin layout).
 *
 * Areas register their pages under the route names below; an item links to its route when it exists
 * (Route::has) and renders as a muted "#" link until then. Sub-items only show while their section is active.
 * Client pages are added with Commerce::adminMenu() or config commerce.admin.menu (docs/EXTENDING.md); entries
 * of switched-off features (config commerce.features.*) are hidden.
 */
class Sidebar extends Component
{
    public function render(): View
    {
        $counts = $this->counts();
        $e = fn (string $label, ?string $icon, string $route, array $active, ?string $feature = null, array $children = [], ?int $count = null, ?string $countLabel = null)
            => compact('label', 'icon', 'route', 'active', 'feature', 'children', 'count', 'countLabel') + ['admin' => false];

        // Core menu. 'feature' = switch (config commerce.features.*) that must be on for the entry to show.
        $menu = [
            $e('Home', 'home', 'admin.dashboard', ['admin.dashboard']),
            $e('Orders', 'inbox-stack', 'admin.orders.index', ['admin.orders.*', 'admin.print', 'admin.carts.*'], null, [
                $e('All orders', null, 'admin.orders.index', ['admin.orders.*', 'admin.print']),
                $e('Abandoned checkouts', null, 'admin.carts.index', ['admin.carts.*'], 'abandoned_carts'),
            ], $counts['orders'], 'Orders waiting to be fulfilled'),
            $e('Products', 'tag', 'admin.products.index', ['admin.products.*', 'admin.categories.*', 'admin.attributes.*', 'admin.reviews.*', 'admin.stock-alerts.*'], null, [
                // "Inventory" routes are named admin.products.inventory* – keep them out of "All products"
                $e('All products', null, 'admin.products.index', ['admin.products.index', 'admin.products.create', 'admin.products.edit']),
                $e('Inventory', null, 'admin.products.inventory', ['admin.products.inventory', 'admin.products.inventory.*']),
                $e('Import / Export', null, 'admin.products.csv', ['admin.products.csv', 'admin.products.csv.*'], 'product_csv'),
                $e('Categories', null, 'admin.categories.index', ['admin.categories.*']),
                $e('Attributes', null, 'admin.attributes.index', ['admin.attributes.*']),
                $e('Reviews', null, 'admin.reviews.index', ['admin.reviews.*'], 'reviews', [], $counts['reviews'], 'Reviews waiting for approval'),
                $e('Stock alerts', null, 'admin.stock-alerts.index', ['admin.stock-alerts.*'], 'stock_alerts'),
            ]),
            $e('Customers', 'user-group', 'admin.customers.index', ['admin.customers.*']),
            $e('Discounts', 'receipt-percent', 'admin.coupons.index', ['admin.coupons.*'], 'coupons'),
            $e('Content', 'document-text', 'admin.pages.index', ['admin.pages.*', 'admin.posts.*', 'admin.post-categories.*', 'admin.menus.*', 'admin.media.*', 'admin.redirects.*'], null, [
                $e('Pages', null, 'admin.pages.index', ['admin.pages.*']),
                $e('Blog posts', null, 'admin.posts.index', ['admin.posts.*'], 'blog'),
                $e('Blog categories', null, 'admin.post-categories.index', ['admin.post-categories.*'], 'blog'),
                $e('Menus', null, 'admin.menus.index', ['admin.menus.*']),
                $e('Media', null, 'admin.media.index', ['admin.media.index', 'admin.media.edit', 'admin.media.show', 'admin.media.create']),
                $e('Redirects', null, 'admin.redirects.index', ['admin.redirects.*'], 'redirects'),
            ]),
            $e('Inbox', 'envelope', 'admin.form-submissions.index', ['admin.form-submissions.*', 'admin.newsletter.*'], 'contact_form', [
                $e('Form submissions', null, 'admin.form-submissions.index', ['admin.form-submissions.*'], 'contact_form', [], $counts['inbox'], 'Unread'),
                $e('Newsletter', null, 'admin.newsletter.index', ['admin.newsletter.*'], 'newsletter'),
            ], $counts['inbox'], 'Unread form submissions'),
            $e('Analytics', 'chart-bar', 'admin.reports.index', ['admin.reports.*'], 'reports'),
        ];

        $sections = array_values(array_filter(array_map(fn (array $entry) => $this->resolveEntry($entry), $this->extend($menu))));

        $settings = $this->item('Settings', 'cog-6-tooth', 'admin.settings.index', ['admin.settings.*', 'admin.staff.*', 'admin.shipping.*', 'admin.payments.*']);

        return view('commerce::admin.partials.sidebar', ['sections' => $sections, 'settings' => $settings, 'updates' => $this->updates(),
            'import' => $this->import()]);
    }

    /** Admin › Updates (administrators, feature "updater"), with a badge while a newer release is available. */
    protected function updates(): ?array
    {
        $user = request()->user();
        if (! Features::enabled('updater', false) || ! $user || ! method_exists($user, 'isAdmin') || ! $user->isAdmin()
            || ! Route::has('admin.updates.index')) {
            return null;
        }
        try {
            $available = \Pine\Commerce\Updater\UpdateChecker::status()['available'] ? 1 : null;
        } catch (Throwable) {
            $available = null; // e.g. before the platform_updates migration ran
        }

        return $this->item('Updates', 'arrow-path', 'admin.updates.index', ['admin.updates.*'], $available, 'Platform update available');
    }

    /** Admin › Import › WooCommerce API (administrators, feature "woo_api_import"). */
    protected function import(): ?array
    {
        $user = request()->user();
        if (! Features::enabled('woo_api_import', false) || ! $user || ! method_exists($user, 'isAdmin') || ! $user->isAdmin()
            || ! Route::has('admin.import.woo.index')) {
            return null;
        }

        return $this->item('Import', 'arrow-down-on-square-stack', 'admin.import.woo.index', ['admin.import.*']);
    }

    /** Merge client entries (Commerce::adminMenu(), config commerce.admin.menu) into the core menu. */
    protected function extend(array $menu): array
    {
        $extra = app(AdminMenu::class);
        foreach ($extra->items() as $entry) {
            $at = null;
            foreach ($menu as $i => $item) {
                if ($entry['after'] !== null && $item['route'] === $entry['after']) {
                    $at = $i + 1;
                }
            }
            array_splice($menu, $at ?? count($menu), 0, [$entry]);
        }
        foreach ($extra->children() as $parent => $children) {
            foreach ($menu as $i => $item) {
                if ($item['route'] === $parent) {
                    $menu[$i]['children'] = array_merge($item['children'], $children);
                    $menu[$i]['active'] = array_values(array_unique(array_merge($item['active'], ...array_map(fn ($c) => $c['active'], $children))));
                }
            }
        }

        return $menu;
    }

    /**
     * Entry → view item, or null when hidden (removed, feature off, administrators-only). A section whose own page is
     * hidden links to its first visible child (e.g. Inbox › Newsletter while contact_form is off); with no visible
     * child it is hidden too.
     */
    protected function resolveEntry(array $entry): ?array
    {
        $menu = app(AdminMenu::class);
        if ($menu->isRemoved($entry['route'])) {
            return null;
        }
        $children = [];
        $first = null;
        foreach ($entry['children'] as $child) {
            if (! $menu->isRemoved($child['route']) && $this->allowed($child)) {
                $first ??= $child;
                $children[] = $this->item($child['label'], null, $child['route'], $child['active'], $this->number($child['count'] ?? null), $child['countLabel'] ?? null);
            }
        }
        if (! $this->allowed($entry)) {
            if (! $first) {
                return null;
            }
            $entry['route'] = $first['route'];
            $entry['count'] = $first['count'] ?? null;
        }

        return $this->item($entry['label'], $entry['icon'], $entry['route'], $entry['active'], $this->number($entry['count'] ?? null), $entry['countLabel'] ?? null, $children);
    }

    protected function allowed(array $entry): bool
    {
        if (! empty($entry['feature']) && ! Features::enabled((string) $entry['feature'], false)) {
            return false;
        }
        if (! empty($entry['admin'])) {
            $user = request()->user();

            return $user && method_exists($user, 'isAdmin') && $user->isAdmin();
        }

        return true;
    }

    protected function number(mixed $count): ?int
    {
        if (is_callable($count)) {
            try {
                $count = $count();
            } catch (Throwable) {
                $count = null;
            }
        }

        return is_numeric($count) ? (int) $count : null;
    }

    /** @return array{label:string, icon:?string, url:string, exists:bool, active:bool, count:?int, countLabel:?string, children:array} */
    protected function item(string $label, ?string $icon, string $route, array $patterns, ?int $count = null, ?string $countLabel = null, array $children = []): array
    {
        $exists = Route::has($route);

        return [
            'label' => $label,
            'icon' => $icon,
            'url' => $exists ? route($route) : '#',
            'exists' => $exists,
            'active' => request()->routeIs(...$patterns),
            'count' => $count ?: null,
            'countLabel' => $countLabel,
            'children' => $children,
        ];
    }

    /** One query for every badge in the menu. */
    protected function counts(): array
    {
        try {
            [$orders, $inbox, $reviews] = [Sql::table('orders'), Sql::table('form_submissions'), Sql::table('product_reviews')];
            $row = DB::selectOne(
                "SELECT
                    (SELECT COUNT(*) FROM {$orders} WHERE status = 'processing' AND deleted_at IS NULL) AS orders,
                    (SELECT COUNT(*) FROM {$inbox} WHERE read_at IS NULL) AS inbox,
                    (SELECT COUNT(*) FROM {$reviews} WHERE is_approved = 0) AS reviews"
            );
        } catch (Throwable) {
            $row = null;
        }

        return [
            'orders' => (int) ($row->orders ?? 0),
            'inbox' => (int) ($row->inbox ?? 0),
            'reviews' => (int) ($row->reviews ?? 0),
        ];
    }
}
