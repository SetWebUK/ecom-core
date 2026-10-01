<?php

namespace Pine\Commerce\Theme;

use Illuminate\Contracts\Foundation\Application;

/**
 * Optional PHP side of a theme: themes/{slug}/Theme.php declares a subclass and names it in theme.json "class".
 * Every theme of the active chain is instantiated (parents first), so a child theme keeps its parent's hooks.
 * See docs/ARCHITECTURE.md §7.5.
 */
abstract class ThemeDefinition
{
    public function __construct(protected Theme $theme) {}

    public function theme(): Theme
    {
        return $this->theme;
    }

    /** Container bindings (runs in the package provider's register phase, after theme paths are set). */
    public function register(Application $app): void {}

    /** View composers, Blade directives, shortcodes, presenters, menu locations. */
    public function boot(): void {}

    /**
     * View composers for this theme's views, registered by the package once the theme boots and applied only while it
     * is in the active theme chain: view name (bare 'product.show' also matches 'theme::product.show'; wildcards and
     * comma lists allowed) => closure(View $view) or a class with compose(View $view).
     *
     *   return ['product.show' => fn ($view) => $view->with('deliveryPromise', setting('client.delivery_promise')),
     *           'partials.header, partials.footer' => \Themes\Acme\Composers\StoreDetails::class];
     *
     * @return array<string, \Closure|class-string>
     */
    public function composers(): array
    {
        return [];
    }

    /** Extra storefront routes; loaded inside Route::middleware('web'). The catch-all is a fallback route, so no ordering care. */
    public function routes(): void {}

    /**
     * Body classes for a core-rendered storefront view. $key is the contract key (ARCHITECTURE §8, e.g. 'catalog.category');
     * $classes is what core computed. Called BEFORE core appends ' paged paged-N'.
     */
    public function bodyClass(string $key, string $classes, array $data): string
    {
        return $classes;
    }
}
