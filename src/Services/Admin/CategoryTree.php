<?php

namespace Pine\Commerce\Services\Admin;

use Pine\Commerce\Support\Sql;
use Pine\Commerce\Models\Category;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Category tree helpers for selects, pickers and the Categories page. Everything is built from one query per
 * request (Category::descendantIds()/ancestors() lazy-load one level at a time – avoid them in lists).
 */
class CategoryTree
{
    protected static ?Collection $flat = null;

    /** All categories in tree order (siblings by sort_order, then name), each with a `depth` attribute. */
    public static function flat(): Collection
    {
        if (static::$flat !== null) {
            return static::$flat;
        }

        $all = Category::query()->orderBy('sort_order')->orderBy('name')
            ->get(['id', 'parent_id', 'name', 'slug', 'path', 'sort_order', 'is_visible', 'show_in_menu', 'image']);
        $byParent = $all->groupBy(fn (Category $c) => (int) $c->parent_id);
        $ordered = collect();
        $seen = [];
        $walk = function (int $parentId, int $depth) use (&$walk, &$seen, $byParent, $ordered): void {
            foreach ($byParent->get($parentId, collect()) as $category) {
                if (isset($seen[$category->id])) {
                    continue; // corrupt data (a cycle) – never loop forever
                }
                $seen[$category->id] = true;
                $category->setAttribute('depth', $depth);
                $ordered->push($category);
                if ($depth < 10) {
                    $walk($category->id, $depth + 1);
                }
            }
        };
        $walk(0, 0);

        // Orphans (parent missing) are appended so nothing is ever hidden
        foreach ($all as $category) {
            if (! isset($seen[$category->id])) {
                $category->setAttribute('depth', 0);
                $ordered->push($category);
            }
        }

        return static::$flat = $ordered;
    }

    /** id => indented name, for selects. */
    public static function options(?int $excludeId = null): array
    {
        $excluded = $excludeId ? static::descendantIds($excludeId) : [];

        return static::flat()
            ->reject(fn (Category $c) => in_array($c->id, $excluded, true))
            ->mapWithKeys(fn (Category $c) => [$c->id => str_repeat('— ', (int) $c->depth).$c->name])
            ->all();
    }

    /** @return list<int> the category and all of its descendants */
    public static function descendantIds(int $id): array
    {
        $children = static::flat()->groupBy(fn (Category $c) => (int) $c->parent_id);
        $ids = [];
        $stack = [$id];
        while ($stack) {
            $current = array_pop($stack);
            if (in_array($current, $ids, true)) {
                continue;
            }
            $ids[] = $current;
            foreach ($children->get($current, collect()) as $child) {
                $stack[] = $child->id;
            }
        }

        return $ids;
    }

    /** @return list<Category> root first, excluding the category itself */
    public static function ancestors(int $id): array
    {
        $byId = static::flat()->keyBy('id');
        $chain = [];
        $seen = [$id];
        $parentId = $byId->get($id)?->parent_id;
        while ($parentId && ! in_array($parentId, $seen, true) && ($parent = $byId->get($parentId))) {
            array_unshift($chain, $parent);
            $seen[] = $parent->id;
            $parentId = $parent->parent_id;
        }

        return $chain;
    }

    /** Products (not deleted) directly in each category: [category_id => count]. */
    public static function productCounts(): array
    {
        return DB::table('category_product')
            ->join('products', 'products.id', '=', 'category_product.product_id')
            ->whereNull('products.deleted_at')
            ->groupBy('category_product.category_id')
            ->selectRaw(Sql::qualify('category_product.category_id, COUNT(*) as aggregate', ['category_product', 'products']))
            ->pluck('aggregate', 'category_id')
            ->map(fn ($n) => (int) $n)
            ->all();
    }

    /**
     * Nested tree for the Categories page: [['category' => Category, 'count' => n, 'total' => n incl. descendants, 'children' => [...]], …]
     */
    public static function nested(): array
    {
        $counts = static::productCounts();
        $byParent = static::flat()->groupBy(fn (Category $c) => (int) $c->parent_id);
        $known = static::flat()->pluck('id')->flip();

        $build = function (int $parentId, int $depth) use (&$build, $byParent, $counts): array {
            $nodes = [];
            foreach ($byParent->get($parentId, collect()) as $category) {
                $children = $depth < 10 ? $build($category->id, $depth + 1) : [];
                $nodes[] = [
                    'category' => $category,
                    'count' => $counts[$category->id] ?? 0,
                    'children' => $children,
                ];
            }

            return $nodes;
        };
        $tree = $build(0, 0);

        // Orphans (parent id points at a missing category) at the root
        foreach (static::flat() as $category) {
            if ($category->parent_id && ! $known->has($category->parent_id)) {
                $tree[] = ['category' => $category, 'count' => $counts[$category->id] ?? 0, 'children' => []];
            }
        }

        return $tree;
    }

    /** Next sort_order for a new child of $parentId (appended at the end). */
    public static function nextSortOrder(?int $parentId): int
    {
        return (int) Category::query()->where('parent_id', $parentId)->max('sort_order') + 1;
    }

    /** Whether making $parentId the parent of $id would create a cycle. */
    public static function wouldCycle(int $id, ?int $parentId): bool
    {
        return $parentId !== null && in_array($parentId, static::descendantIds($id), true);
    }

    public static function flush(): void
    {
        static::$flat = null;
    }
}
