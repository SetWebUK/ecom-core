<?php

namespace Pine\Commerce\View\Components;

/**
 * Mobile off-canvas menu (menu location "mobile_nav", falling back to "mobile"). Items with children render
 * as accordions; without a menu the theme's menus.fallbacks.mobile_nav list is used.
 */
class MobileMenu extends MenuComponent
{
    public array $items;

    /** Footer buttons of the drawer: items whose css class contains mnav-cta / mnav-help (e.g. "mnav-cta"). */
    public ?array $cta = null;

    public ?array $help = null;

    public function __construct()
    {
        $items = static::load(['mobile_nav', 'mobile'], static::defaults());
        foreach ($items as $i => $item) {
            $class = (string) ($item['class'] ?? '');
            if (str_contains($class, 'mnav-cta')) {
                $this->cta ??= $item;
                unset($items[$i]);
            } elseif (str_contains($class, 'mnav-help')) {
                $this->help ??= $item;
                unset($items[$i]);
            }
        }
        $this->items = array_values($items);
    }

    public function render()
    {
        return view('partials.mobile-menu');
    }

    /** Fallback when no menu location has items: the active theme's config menus.fallbacks.mobile_nav. */
    public static function defaults(): array
    {
        return static::fallback('mobile_nav');
    }
}
