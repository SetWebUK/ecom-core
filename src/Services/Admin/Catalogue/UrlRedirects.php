<?php

namespace Pine\Commerce\Services\Admin\Catalogue;

use Pine\Commerce\Models\Category;
use Pine\Commerce\Models\Product;
use Pine\Commerce\Models\Redirect;
use Pine\Commerce\Services\Admin\CategoryTree;
use Illuminate\Support\Facades\DB;

/**
 * 301 redirects for catalogue URLs that change (category slug/parent edits, category moves and deletes, product slug
 * edits). Rows go into the `redirects` table read by the storefront's ResolveController: from_path is the lower-cased
 * path without slashes ("old-category/old-name"), to_url is root-relative with a trailing slash ("/new-path/").
 *
 *   $before = UrlRedirects::snapshot($categoryIds);   // before saving
 *   …change slugs / parents…
 *   $count = UrlRedirects::fromSnapshot($before);    // after saving: one redirect per URL that moved
 */
class UrlRedirects
{
    /**
     * Record the current URL paths of these categories (and their descendants) and of every product listed in them.
     *
     * @param  list<int>  $categoryIds
     * @return array{categories: array<int,string>, products: list<array{0:int,1:string}>}
     */
    public static function snapshot(array $categoryIds): array
    {
        CategoryTree::flush();
        $ids = collect($categoryIds)->flatMap(fn ($id) => CategoryTree::descendantIds((int) $id))->unique()->values()->all();
        if (! $ids) {
            return ['categories' => [], 'products' => []];
        }
        $paths = Category::query()->whereIn('id', $ids)->pluck('path', 'id')->all();

        $products = DB::table('category_product')
            ->join('products', 'products.id', '=', 'category_product.product_id')
            ->whereIn('category_product.category_id', $ids)
            ->whereNull('products.deleted_at')
            ->get(['products.id', 'products.slug', 'category_product.category_id'])
            ->map(fn ($row) => [(int) $row->id, $paths[$row->category_id].'/'.$row->slug])
            ->values()->all();

        return ['categories' => $paths, 'products' => $products];
    }

    /** Create redirects for every snapshotted URL whose target moved. Returns the number of redirects written. */
    public static function fromSnapshot(array $snapshot, ?array $categoryTargets = null): int
    {
        CategoryTree::flush();
        if (class_exists(\Pine\Commerce\Services\Catalog\Categories::class)) {
            \Pine\Commerce\Services\Catalog\Categories::flush();
        }
        $map = [];

        $current = Category::query()->whereIn('id', array_keys($snapshot['categories'] ?? []))->pluck('path', 'id')->all();
        foreach ($snapshot['categories'] ?? [] as $id => $oldPath) {
            $newPath = $categoryTargets[$id] ?? ($current[$id] ?? null);
            if ($newPath !== null && $newPath !== $oldPath) {
                $map[$oldPath] = '/'.trim($newPath, '/').'/';
            }
        }

        $productIds = collect($snapshot['products'] ?? [])->pluck(0)->unique()->values();
        if ($productIds->isNotEmpty()) {
            $products = Product::query()->whereIn('id', $productIds)->with(['primaryCategory:id,path', 'categories:id,path'])
                ->get(['id', 'slug', 'primary_category_id'])->keyBy('id');
            foreach ($snapshot['products'] as [$productId, $oldPath]) {
                $product = $products->get($productId);
                if (! $product) {
                    continue;
                }
                $newPath = trim((string) parse_url($product->url, PHP_URL_PATH), '/');
                if ($newPath !== '' && strtolower($newPath) !== strtolower($oldPath)) {
                    $map[$oldPath] = '/'.$newPath.'/';
                }
            }
        }

        return static::addMany($map);
    }

    /** One redirect (e.g. after a product's slug changed). */
    public static function add(string $fromPath, string $toUrl): int
    {
        return static::addMany([$fromPath => $toUrl]);
    }

    /**
     * Write redirects [old path => new URL]. Skips no-ops, removes rules that would now shadow a live URL and
     * re-points older redirects that led to an old path (no chains, no loops).
     *
     * @param  array<string,string>  $map
     */
    public static function addMany(array $map): int
    {
        $rows = [];
        foreach ($map as $from => $to) {
            $from = static::normalise($from);
            $to = static::target($to);
            if ($from === '' || $from === static::normalise($to)) {
                continue;
            }
            $rows[$from] = $to;
        }
        if (! $rows) {
            return 0;
        }

        DB::transaction(function () use ($rows) {
            $now = now();
            // A rule from a path that is live again (e.g. a slug changed back) would loop – drop it.
            $livePaths = array_map(fn ($to) => static::normalise($to), array_values($rows));
            Redirect::query()->whereIn('from_path', array_unique($livePaths))->delete();

            // Flatten chains: redirects that pointed at an old path now go straight to the new one.
            foreach ($rows as $from => $to) {
                Redirect::query()->whereIn('to_url', ['/'.$from.'/', '/'.$from, $from])->update(['to_url' => $to, 'updated_at' => $now]);
            }

            foreach (array_chunk($rows, 200, true) as $chunk) {
                $insert = [];
                foreach ($chunk as $from => $to) {
                    $insert[] = ['from_path' => $from, 'to_url' => $to, 'status_code' => 301, 'is_active' => true, 'hits' => 0, 'created_at' => $now, 'updated_at' => $now];
                }
                Redirect::query()->upsert($insert, ['from_path'], ['to_url', 'status_code', 'is_active', 'updated_at']);
            }
        });

        return count($rows);
    }

    /** "/Old-Category/x/" -> "old-category/x" (the format ResolveController looks up). */
    public static function normalise(string $path): string
    {
        $path = (string) (parse_url($path, PHP_URL_PATH) ?? $path);

        return strtolower(trim(rawurldecode($path), '/'));
    }

    /** Root-relative target with a trailing slash ("/new-path/"); absolute URLs on this site are made relative. */
    protected static function target(string $url): string
    {
        $path = preg_match('#^https?://#i', $url) ? (string) parse_url($url, PHP_URL_PATH) : $url;
        $path = trim($path, '/');

        return $path === '' ? '/' : '/'.$path.'/';
    }
}
