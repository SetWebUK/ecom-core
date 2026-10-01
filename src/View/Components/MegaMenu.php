<?php

namespace Pine\Commerce\View\Components;

/**
 * Desktop mega menu (header). Menu location "mega" (falls back to "main", then to the theme's menus.fallbacks.mega).
 *
 * How a top-level item's children are laid out (same rules the admin uses when building the menu):
 *  - children that have an image (icon) and no children   -> image tile panel
 *  - children with a null URL or their own children        -> column panel: each child is a heading + link list
 *      * css_class "same-column" stacks the group under the previous column
 *      * css_class "promo" + icon                          -> promo image column
 *  - badge on a link renders the orange "popular" style tag
 */
class MegaMenu extends MenuComponent
{
    /** Link columns in a mega panel (plus the promo image). */
    public const MAX_COLUMNS = 4;

    public array $items;

    public function __construct()
    {
        $this->items = static::load(['mega', 'main'], static::defaults());
    }

    /** Split a top-level item's children into a panel description for the view. */
    public static function panel(array $item): ?array
    {
        $children = $item['children'] ?? [];
        if (! $children) {
            return null;
        }
        $isTiles = collect($children)->every(fn ($c) => ! empty($c['icon']) && empty($c['children']) && ! str_contains((string) $c['class'], 'promo'));
        if ($isTiles) {
            return ['type' => 'tiles', 'tiles' => $children];
        }

        $columns = [];
        $promo = null;
        $loose = [];
        foreach ($children as $child) {
            $class = (string) ($child['class'] ?? '');
            if (str_contains($class, 'promo') && ! empty($child['icon'])) {
                $promo = $child;

                continue;
            }
            if (empty($child['children']) && ! empty($child['url'])) {
                $loose[] = $child; // plain link directly under the top item

                continue;
            }
            if (str_contains($class, 'same-column') && $columns) {
                $columns[count($columns) - 1][] = $child;
            } else {
                $columns[] = [$child];
            }
        }
        if ($loose) {
            $columns[] = [['label' => null, 'url' => null, 'children' => $loose]];
        }
        // Without explicit "same-column" markers, stack consecutive groups so the panel keeps the live
        // layout of at most MAX_COLUMNS link columns (balanced by number of links).
        $explicit = collect($children)->contains(fn ($c) => str_contains((string) ($c['class'] ?? ''), 'same-column'));
        if (! $explicit && count($columns) > self::MAX_COLUMNS) {
            $columns = static::balance(array_map(fn ($col) => $col[0], $columns), self::MAX_COLUMNS);
        }

        return ['type' => 'columns', 'columns' => $columns, 'promo' => $promo];
    }

    /**
     * Split groups (in order) into $k contiguous columns minimising the tallest column
     * (weight = links + heading).
     */
    protected static function balance(array $groups, int $k): array
    {
        $n = count($groups);
        $w = array_map(fn ($g) => count($g['children'] ?? []) + 1, $groups);
        $prefix = [0];
        foreach ($w as $i => $x) {
            $prefix[$i + 1] = $prefix[$i] + $x;
        }
        $sum = fn ($a, $b) => $prefix[$b] - $prefix[$a]; // groups a..b-1
        $best = array_fill(0, $n + 1, array_fill(0, $k + 1, PHP_INT_MAX));
        $cut = [];
        $best[0][0] = 0;
        for ($i = 1; $i <= $n; $i++) {
            for ($j = 1; $j <= min($i, $k); $j++) {
                for ($p = $j - 1; $p < $i; $p++) {
                    if ($best[$p][$j - 1] === PHP_INT_MAX) {
                        continue;
                    }
                    $cost = max($best[$p][$j - 1], $sum($p, $i));
                    if ($cost < $best[$i][$j]) {
                        $best[$i][$j] = $cost;
                        $cut[$i][$j] = $p;
                    }
                }
            }
        }
        $columns = [];
        for ($i = $n, $j = $k; $j > 0; $j--) {
            $p = $cut[$i][$j];
            array_unshift($columns, array_slice($groups, $p, $i - $p));
            $i = $p;
        }

        return $columns;
    }

    public function render()
    {
        return view('partials.mega-menu');
    }

    /** Fallback when no menu location has items: the active theme's config menus.fallbacks.mega (default: none). */
    public static function defaults(): array
    {
        return static::fallback('mega');
    }
}
