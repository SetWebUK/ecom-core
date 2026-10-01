<?php

namespace Pine\Commerce\View\Components;

/**
 * A footer link column (menu locations footer_shop / footer_company) or the legal links row
 * (footer_legal). The column heading is the menu's name; fallbacks come from the theme's menus.fallbacks.
 */
class FooterMenu extends MenuComponent
{
    public array $items;

    public string $title;

    public function __construct(public string $location = 'footer_shop', public string $variant = 'column')
    {
        $defaults = static::defaults()[$location] ?? ['title' => '', 'items' => []];
        $this->items = static::load([$location], $defaults['items']);
        $name = trim((string) preg_replace('/^footer\s*[\x{2013}\x{2014}:\-]\s*/iu', '', (string) static::menuName($location)));
        $this->title = $name !== '' ? $name : $defaults['title'];
    }

    public function render()
    {
        return view('partials.footer-menu');
    }

    /**
     * Fallback columns when a location has no items: the active theme's config menus.fallbacks.{location}, either
     * ['title' => …, 'items' => [...]] or a plain list of items.
     *
     * @return array<string, array{title:string, items:array}>
     */
    public static function defaults(): array
    {
        $out = [];
        foreach ((array) theme_config('menus.fallbacks', []) as $location => $fallback) {
            if (str_starts_with((string) $location, 'footer_') && is_array($fallback)) {
                $out[$location] = array_key_exists('items', $fallback)
                    ? ['title' => (string) ($fallback['title'] ?? ''), 'items' => (array) $fallback['items']]
                    : ['title' => '', 'items' => array_values($fallback)];
            }
        }

        return $out;
    }
}
