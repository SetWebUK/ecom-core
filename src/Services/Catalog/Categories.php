<?php

namespace Pine\Commerce\Services\Catalog;

use Pine\Commerce\Models\Category;
use Illuminate\Support\Collection;

/**
 * In-memory category tree for the storefront (one query per request, instead of the recursive
 * lazy-loading in Category::descendantIds()/ancestors()).
 */
class Categories
{
    protected static ?Collection $all = null;

    /** @return Collection<int, Category> keyed by id */
    public static function all(): Collection
    {
        return static::$all ??= Category::query()->orderBy('sort_order')->orderBy('name')->get()->keyBy('id');
    }

    public static function flush(): void
    {
        static::$all = null;
    }

    public static function find(?int $id): ?Category
    {
        return $id ? static::all()->get($id) : null;
    }

    public static function byPath(string $path): ?Category
    {
        $path = strtolower(trim($path, '/'));

        return static::all()->first(fn (Category $c) => strtolower($c->path) === $path);
    }

    /** @return int[] the category and all of its descendants */
    public static function descendantIds(int $id): array
    {
        $children = static::all()->groupBy('parent_id');
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

    /** @return Category[] root first, excluding the category itself */
    public static function ancestors(Category $category): array
    {
        $chain = [];
        $seen = [$category->id];
        $parentId = $category->parent_id;
        while ($parentId && ! in_array($parentId, $seen, true) && ($parent = static::find($parentId))) {
            array_unshift($chain, $parent);
            $seen[] = $parent->id;
            $parentId = $parent->parent_id;
        }

        return $chain;
    }

    /** @return Collection<int, Category> visible direct children */
    public static function children(Category $category): Collection
    {
        return static::all()->where('parent_id', $category->id)->where('is_visible', true)->values();
    }
}
