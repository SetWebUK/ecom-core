<?php

namespace Pine\Commerce\Import\Permalinks;

use Pine\Commerce\Import\Contracts\PermalinkProvider;
use Pine\Commerce\Import\Contracts\SeoProvider;
use Pine\Commerce\Import\Data\WpPost;
use Pine\Commerce\Import\ImportContext;

/**
 * The source site's real URLs, per type (products, categories, pages, posts): wp id => path (no slashes).
 * PermalinkProvider adapters (wp-cli, Premmerce, Permalink Manager …) are merged by priority, the core
 * PermalinkBuilder fills every id they do not know. Built lazily, once per run.
 */
class Permalinks
{
    private ?array $all = null;

    /** @var array<string,array<string,int>> type => provider label => count */
    public array $sources = [];

    private ?PermalinkBuilder $builder = null;

    private ?array $products = null;

    private ?array $categoryTree = null;

    public function __construct(private readonly ImportContext $ctx) {}

    /** @return array{products: array<int,string>, categories: array<int,string>, pages: array<int,string>, posts: array<int,string>} */
    public function all(): array
    {
        if ($this->all !== null) {
            return $this->all;
        }
        $out = ['products' => [], 'categories' => [], 'pages' => [], 'posts' => []];
        foreach ($this->ctx->adapters->providers(PermalinkProvider::class) as $adapter) {
            try {
                $maps = $adapter->permalinks();
            } catch (\Throwable $e) {
                $this->ctx->warn('Permalinks from '.$adapter->label().' failed: '.$e->getMessage());
                $maps = [];
            }
            foreach ($out as $type => $_) {
                foreach ($maps[$type] ?? [] as $id => $path) {
                    if (! isset($out[$type][$id]) && $path !== null) {
                        $out[$type][(int) $id] = trim((string) $path, '/');
                        $this->sources[$type][$adapter->label()] = ($this->sources[$type][$adapter->label()] ?? 0) + 1;
                    }
                }
            }
        }
        foreach ($this->core() as $type => $map) {
            foreach ($map as $id => $path) {
                if (! isset($out[$type][$id]) && $path !== null) {
                    $out[$type][$id] = $path;
                    $this->sources[$type]['settings'] = ($this->sources[$type]['settings'] ?? 0) + 1;
                }
            }
        }

        return $this->all = $out;
    }

    public function products(): array
    {
        return $this->all()['products'];
    }

    public function categories(): array
    {
        return $this->all()['categories'];
    }

    public function pages(): array
    {
        return $this->all()['pages'];
    }

    public function posts(): array
    {
        return $this->all()['posts'];
    }

    public function builder(): PermalinkBuilder
    {
        if ($this->builder === null) {
            $site = $this->ctx->site;
            $this->builder = new PermalinkBuilder($site->permalinkStructure, $site->woo['permalinks']['product_base'] ?? '',
                $site->woo['permalinks']['category_base'] ?? '', $site->woo['permalinks']['tag_base'] ?? '', $this->categoryTree());
        }

        return $this->builder;
    }

    /** @return array<int,array{slug:string,parent:int}> product_cat tree */
    public function categoryTree(): array
    {
        if ($this->categoryTree === null) {
            $this->categoryTree = [];
            foreach ($this->ctx->wp->terms('product_cat') as $id => $t) {
                $this->categoryTree[(int) $id] = ['slug' => urldecode((string) $t->slug), 'parent' => (int) $t->parent];
            }
        }

        return $this->categoryTree;
    }

    /**
     * Products with URL-relevant data: id => [slug, status, categories (term ids), primary (SEO plugin primary term or null)].
     *
     * @return array<int,array{slug:string,status:string,categories:list<int>,primary:?int}>
     */
    public function productData(): array
    {
        if ($this->products !== null) {
            return $this->products;
        }
        $wp = $this->ctx->wp;
        $posts = $wp->table('posts')->where('post_type', 'product')->whereNotIn('post_status', ['trash', 'auto-draft'])
            ->orderBy('ID')->get(['ID', 'post_name', 'post_title', 'post_status', 'post_type', 'post_parent', 'menu_order', 'post_date_gmt', 'post_modified_gmt']);
        $terms = $wp->objectTerms($posts->pluck('ID')->all(), 'product_cat');
        $seo = $this->ctx->adapters->providers(SeoProvider::class);
        $this->products = [];
        foreach ($posts->chunk(1000) as $chunk) {
            $meta = $seo ? $wp->postMeta($chunk->pluck('ID')->all()) : [];
            foreach ($chunk as $p) {
                $cats = array_map(fn ($t) => (int) $t->term_id, $terms[$p->ID] ?? []);
                $primary = null;
                foreach ($seo as $provider) {
                    $primary = $provider->primaryTermId(WpPost::fromRow($p), $meta[$p->ID] ?? [], 'product_cat');
                    if ($primary !== null) {
                        break;
                    }
                }
                $this->products[(int) $p->ID] = [
                    'slug' => $p->post_name !== '' ? urldecode($p->post_name) : '',
                    'status' => $p->post_status,
                    'categories' => $cats,
                    'primary' => $primary && in_array($primary, $cats, true) ? $primary : null,
                ];
            }
        }

        return $this->products;
    }

    /** URLs derived from WordPress/WooCommerce settings (fallback for ids no provider knows). */
    private function core(): array
    {
        $builder = $this->builder();
        $wp = $this->ctx->wp;
        $out = ['products' => [], 'categories' => [], 'pages' => [], 'posts' => []];
        foreach (array_keys($this->categoryTree()) as $id) {
            $out['categories'][$id] = $builder->categoryPath($id);
        }
        foreach ($this->productData() as $id => $p) {
            if ($p['status'] === 'publish' && $p['slug'] !== '') {
                $out['products'][$id] = $builder->productPath($p['slug'], $p['categories'], $p['primary']);
            }
        }
        $pages = [];
        foreach ($wp->table('posts')->where('post_type', 'page')->where('post_status', 'publish')->get(['ID', 'post_name', 'post_parent']) as $p) {
            $pages[(int) $p->ID] = ['slug' => urldecode((string) $p->post_name), 'parent' => (int) $p->post_parent];
        }
        foreach (array_keys($pages) as $id) {
            $out['pages'][$id] = PermalinkBuilder::pagePath($id, $pages, $this->ctx->site->pageOnFront);
        }
        if ($this->ctx->site->permalinkStructure !== '') {
            $posts = $wp->table('posts')->where('post_type', 'post')->where('post_status', 'publish')->get(['ID', 'post_name', 'post_date', 'post_author']);
            $needsCategory = str_contains($this->ctx->site->permalinkStructure, '%category%');
            $needsAuthor = str_contains($this->ctx->site->permalinkStructure, '%author%');
            $cats = $needsCategory ? $wp->objectTerms($posts->pluck('ID')->all(), 'category') : [];
            $catTree = [];
            if ($needsCategory) {
                foreach ($wp->terms('category') as $id => $t) {
                    $catTree[(int) $id] = ['slug' => urldecode((string) $t->slug), 'parent' => (int) $t->parent];
                }
            }
            $authors = $needsAuthor ? $wp->table('users')->pluck('user_nicename', 'ID')->all() : [];
            foreach ($posts as $p) {
                $category = null;
                if ($needsCategory) {
                    $ids = array_map(fn ($t) => (int) $t->term_id, $cats[$p->ID] ?? []);
                    sort($ids);
                    $category = $ids ? PermalinkBuilder::pagePath($ids[0], $catTree) : null;
                }
                $out['posts'][(int) $p->ID] = $builder->postPath(['slug' => urldecode((string) $p->post_name), 'id' => (int) $p->ID,
                    'date' => $p->post_date, 'category' => $category, 'author' => $authors[$p->post_author] ?? null]);
            }
        }

        return $out;
    }
}
