<?php

namespace Pine\Commerce\Import\Steps;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Pine\Commerce\Import\Contracts\TermMapper;
use Pine\Commerce\Import\Data\WpTerm;
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
        DB::table('products')->whereNotNull('wp_id')->update(['primary_category_id' => null]);
        DB::table('categories')->whereNotNull('wp_id')->update(['parent_id' => null]);
        DB::table('categories')->whereNotNull('wp_id')->delete();
    }

    /** @return array<int,string> term id => Laravel path */
    public static function paths(iterable $terms): array
    {
        $byId = [];
        foreach ($terms as $id => $t) {
            $byId[(int) $id] = $t;
        }
        $out = [];
        $path = function (int $id, array $seen = []) use (&$path, $byId) {
            $t = $byId[$id];
            $slug = urldecode((string) $t->slug);
            $parent = (int) $t->parent;

            return ($parent && isset($byId[$parent]) && ! isset($seen[$parent]) ? $path($parent, $seen + [$id => true]).'/' : '').$slug;
        };
        foreach (array_keys($byId) as $id) {
            $out[$id] = $path($id);
        }

        return $out;
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
                $row = [
                    'wp_id' => $t->term_id,
                    'parent_id' => $t->parent ? ($map[$t->parent] ?? null) : null,
                    'name' => $name,
                    'slug' => urldecode((string) $t->slug),
                    'path' => $path,
                    'description' => $description,
                    'extra_content' => null,
                    'image' => $this->ctx->attachmentPath($m['thumbnail_id'] ?? 0),
                    'sort_order' => (int) ($m['order'] ?? 0),
                    'is_visible' => true,
                    'show_in_menu' => (int) $t->term_id !== $defaultCat,
                    'meta_title' => Str::limit((string) $seo?->title, 250, '') ?: null,
                    'meta_description' => $seo?->description ?? ($rendered ? $this->ctx->renderedSeo($this->sourcePath($t->term_id, $path))['description'] : null),
                    'created_at' => $this->now(),
                    'updated_at' => $this->now(),
                ];
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
        $this->ctx->count('Product categories', $terms->count(), DB::table('categories')->whereNotNull('wp_id')->count(), $note);
    }

    /** Path of the rendered category page on the source site (its real URL when known). */
    private function sourcePath(int $termId, string $path): string
    {
        return $this->ctx->permalinks()->categories()[$termId] ?? $path;
    }
}
