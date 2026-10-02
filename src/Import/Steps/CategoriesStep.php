<?php

namespace Pine\Commerce\Import\Steps;

use Pine\Commerce\Import\Contracts\TermMapper;
use Pine\Commerce\Import\Data\WpTerm;
use Pine\Commerce\Import\Mapping\CatalogRows;
use Pine\Commerce\Import\Support\Formatter;

/**
 * Product categories (product_cat) -> categories. The Laravel path is the hierarchical slug path ("clothing/shirts"),
 * which is the storefront URL; the source URL (with its category base, if any) is reported and redirected by the
 * redirects step when it differs. SEO from the SEO plugin (+ the rendered meta description as fallback),
 * extra columns via TermMapper adapters (e.g. ACF).
 */
class CategoriesStep extends AbstractStep
{
    public function key(): string
    {
        return 'catalog.categories';
    }

    public function section(): string
    {
        return 'catalog';
    }

    protected function clear(): void
    {
        $this->ctx->owned('products')->update(['primary_category_id' => null]);
        $this->ctx->owned('categories')->update(['parent_id' => null]);
        $this->ctx->owned('categories')->delete();
    }

    /** @return array<int,string> term id => Laravel path */
    public static function paths(iterable $terms): array
    {
        return CatalogRows::paths($terms);
    }

    protected function import(): void
    {
        $terms = $this->wp->terms('product_cat');
        $meta = $this->wp->termMeta($terms->keys()->all());
        $defaultCat = (int) $this->wp->option('default_product_cat', 0);
        $paths = self::paths($terms);
        $mappers = $this->ctx->adapters->providers(TermMapper::class);
        $rendered = (bool) $this->ctx->config('seo.rendered_fallback', true);

        $levels = [];
        foreach ($terms as $id => $t) {
            $levels[substr_count($paths[$id], '/')][] = $t;
        }
        ksort($levels);

        $map = [];
        foreach ($levels as $level) {
            $rows = [];
            foreach ($level as $t) {
                $m = $meta[$t->term_id] ?? [];
                $term = WpTerm::fromRow($t, $m);
                $name = Formatter::decode($t->name);
                $description = Formatter::clean(Formatter::autop($t->description));
                $vars = ['term' => $name, 'term_description' => Formatter::excerpt($description, 100000)]; // SEO plugins output the whole description
                $seo = $this->ctx->termSeo($term, 'product_cat', $vars);
                $path = $paths[$t->term_id];
                $row = CatalogRows::category($term, $path, $t->parent ? ($map[$t->parent] ?? null) : null,
                    $this->ctx->attachmentPath($m['thumbnail_id'] ?? 0), $seo,
                    $seo?->description === null && $rendered ? $this->ctx->renderedSeo($this->sourcePath($t->term_id, $path))['description'] : null,
                    (int) $t->term_id !== $defaultCat, $this->now(), $description);
                foreach ($mappers as $mapper) {
                    $row = $mapper->mapCategory($row, $term, $m, $this->ctx);
                }
                $rows[] = $row;
            }
            $this->ctx->adopt('categories', $rows, 'path');
            $map += $this->ctx->save('categories', $rows, 'wp_id', ['created_at']);
        }

        // URL report: the source site's real category URLs vs the Laravel paths
        $source = $this->ctx->permalinks()->categories();
        $mismatch = 0;
        $legacyBase = 0;
        foreach ($source as $termId => $wpPath) {
            $laravel = $paths[$termId] ?? null;
            if ($laravel === $wpPath) {
                continue;
            }
            if ($laravel !== null && $wpPath === 'product-category/'.$laravel) {
                $legacyBase++; // served by the storefront's built-in /product-category/ redirect

                continue;
            }
            $mismatch++;
            $this->ctx->warn("Category URL differs: term $termId WP=/$wpPath/ Laravel=/".($laravel ?? '?').'/ (redirected)');
        }
        $note = $source ? ($mismatch || $legacyBase ? "$mismatch URLs redirected, $legacyBase via /product-category/ fallback" : 'all '.count($source).' URLs match WP') : '';
        $this->ctx->count('Product categories', $terms->count(), $this->ctx->owned('categories')->count(), $note);
    }

    /** Path of the rendered category page on the source site (its real URL when known). */
    private function sourcePath(int $termId, string $path): string
    {
        return $this->ctx->permalinks()->categories()[$termId] ?? $path;
    }
}
