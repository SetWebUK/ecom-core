<?php

namespace Pine\Commerce\Import\Steps;

use Illuminate\Support\Facades\DB;
use Pine\Commerce\Import\Contracts\RedirectProvider;
use Pine\Commerce\Import\Data\RedirectRule;
use Pine\Commerce\Import\Support\Formatter;

/**
 * Redirects: the plain-URL rules of the redirect plugins (RedirectProvider adapters – Redirection, Rank Math, Yoast
 * Premium – in priority order, first rule for a path wins), path-only rules derived from query-string rules whose
 * variants all agree, old slugs (_wp_old_slug) of products, posts and pages, and "permalink" rules for source URLs
 * the storefront serves elsewhere (e.g. a custom product base, date-based post URLs). Sources are stored as
 * lower-case paths without leading/trailing slash ("guarantee", "old-category/old-slug"); targets as relative
 * URLs with WordPress' trailing slash. Chains are collapsed to the final destination.
 */
class RedirectsStep extends AbstractStep
{
    public function key(): string
    {
        return 'redirects';
    }

    public function section(): string
    {
        return 'redirects';
    }

    public function after(): array
    {
        return ['content.pages', 'content.posts', 'catalog.products'];
    }

    protected function clear(): void
    {
        // Rules are upserted on from_path; admin-created redirects are never removed.
    }

    protected function import(): void
    {
        $live = $this->livePaths();
        $rules = [];
        $skipped = ['regex' => 0, 'query' => 0, 'self' => 0, 'invalid' => 0];

        // Each plugin evaluates its rules in order – the first matching rule wins.
        $items = 0;
        $queryRules = [];
        $bySource = [];
        foreach ($this->ctx->adapters->providers(RedirectProvider::class) as $provider) {
            foreach ($provider->redirects() as $item) {
                /** @var RedirectRule $item */
                $items++;
                if ($item->regex) {
                    $skipped['regex']++;

                    continue;
                }
                $target = $this->target($item->to);
                if ($target === null) {
                    $skipped['invalid']++;

                    continue;
                }
                $url = trim($item->from);
                $from = $this->normalise($url);
                if ($from === null) {
                    $skipped['invalid']++;

                    continue;
                }
                if (str_contains($url, '?')) {
                    // Query-string rules can't be matched on the path alone; keep only if every variant agrees.
                    $queryRules[$from][] = $target;
                    $skipped['query']++;

                    continue;
                }
                if (! isset($rules[$from])) {
                    $bySource[$item->source ?: $provider->key()] = ($bySource[$item->source ?: $provider->key()] ?? 0) + 1;
                }
                $rules[$from] ??= [
                    'to' => $target,
                    'code' => in_array($item->status, [301, 302, 307, 308], true) ? $item->status : 301,
                    'hits' => $item->hits,
                    'last' => self::date($item->lastHitAt),
                ];
            }
        }
        $derived = 0;
        foreach ($queryRules as $from => $targets) {
            if ($from !== '' && ! isset($rules[$from]) && ! isset($live[$from]) && count(array_unique($targets)) === 1) {
                $rules[$from] = ['to' => $targets[0], 'code' => 301, 'hits' => 0, 'last' => null];
                $derived++;
            }
        }
        $pluginRules = count($rules);

        $oldSlugs = $this->oldSlugRules($live);
        foreach ($oldSlugs as $from => $to) {
            $rules[$from] ??= ['to' => $to, 'code' => 301, 'hits' => 0, 'last' => null];
        }
        $permalinkRules = 0;
        foreach ($this->permalinkRules($live) as $from => $to) {
            if (! isset($rules[$from])) {
                $rules[$from] = ['to' => $to, 'code' => 301, 'hits' => 0, 'last' => null];
                $permalinkRules++;
            }
        }

        // Collapse chains (a -> b -> c becomes a -> c) and drop self/looping rules.
        $rows = [];
        $shadowed = 0;
        foreach ($rules as $from => $rule) {
            $to = $rule['to'];
            for ($i = 0; $i < 5; $i++) {
                $next = $rules[$this->normalise($to) ?? ''] ?? null;
                if (! $next || ! str_starts_with($to, '/') || $this->normalise($to) === $from) {
                    break;
                }
                $to = $next['to'];
            }
            if ($this->normalise($to) === $from && ! str_contains($to, '?')) {
                $skipped['self']++;

                continue;
            }
            if (isset($live[$from]) && $live[$from] !== 'page') {
                $shadowed++;
                $this->ctx->warn("Redirect /$from/ → $to shadows a live {$live[$from]} URL (WordPress redirected it and so does this store: an explicit redirect rule wins – delete it in Admin › Content › Redirects to show the {$live[$from]} again)");
            }
            if (mb_strlen($from) > 250 || mb_strlen($to) > 250) {
                $skipped['invalid']++;

                continue;
            }
            $rows[] = [
                'from_path' => $from,
                'to_url' => $to,
                'status_code' => $rule['code'],
                'hits' => $rule['hits'],
                'last_hit_at' => $rule['last'],
                'is_active' => true,
                'created_at' => $this->now(),
                'updated_at' => $this->now(),
            ];
        }
        $this->ctx->save('redirects', $rows, 'from_path', ['hits', 'last_hit_at', 'created_at']);

        $this->ctx->count('Redirects', $items.' enabled plugin rules + '.count($oldSlugs).' old slugs',
            count($rows), 'plugins '.($bySource ? collect($bySource)->map(fn ($c, $k) => "$k $c")->implode('/') : '0')." (+ $derived path-only from query rules), "
            ."permalink changes $permalinkRules, skipped: regex {$skipped['regex']}, query-string {$skipped['query']}, self/loop {$skipped['self']}, invalid {$skipped['invalid']}".($shadowed ? ", $shadowed shadow live URLs" : ''));
    }

    /** Old slugs of products (under each of their category paths), posts and pages. */
    private function oldSlugRules(array $live): array
    {
        $rules = [];
        $products = $this->ctx->owned('products')->where('status', 'published')->get(['id', 'wp_id', 'slug', 'primary_category_id']);
        $paths = DB::table('categories')->pluck('path', 'id')->all();
        $productCats = DB::table('category_product')->get()->groupBy('product_id');
        $old = $this->wp->postMetaMulti($products->pluck('wp_id')->all(), '_wp_old_slug');
        foreach ($products as $p) {
            $canonical = '/'.(isset($paths[$p->primary_category_id]) ? $paths[$p->primary_category_id].'/' : 'product/').$p->slug.'/';
            $bases = collect($productCats->get($p->id, []))->map(fn ($r) => $paths[$r->category_id] ?? null)->push($paths[$p->primary_category_id] ?? null)->filter()->unique();
            foreach (array_unique($old[$p->wp_id] ?? []) as $slug) {
                $slug = strtolower(urldecode(trim($slug)));
                if ($slug === '' || $slug === $p->slug) {
                    continue;
                }
                foreach ($bases as $base) {
                    $from = $base.'/'.$slug;
                    if (! isset($live[$from])) {
                        $rules[$from] = $canonical;
                    }
                }
                if (! isset($live['product/'.$slug])) {
                    $rules['product/'.$slug] = $canonical;
                }
            }
        }

        foreach (['posts' => fn ($r) => '/blog/'.$r->slug.'/', 'pages' => fn ($r) => $r->path === '' ? '/' : '/'.$r->path.'/'] as $table => $urlOf) {
            $rowsById = $this->ctx->owned($table)->where('status', 'published')->get()->keyBy('wp_id');
            foreach ($this->wp->postMetaMulti($rowsById->keys()->all(), '_wp_old_slug') as $wpId => $slugs) {
                $row = $rowsById[$wpId];
                foreach (array_unique($slugs) as $slug) {
                    $slug = strtolower(urldecode(trim($slug)));
                    $from = $table === 'posts' ? 'blog/'.$slug : $slug;
                    if ($slug !== '' && $slug !== $row->slug && ! isset($live[$from])) {
                        $rules[$from] = $urlOf($row);
                    }
                }
            }
        }

        return $rules;
    }

    /**
     * Source URLs the storefront serves at a different path (custom product/category base, date or id based post
     * URLs …): source path => Laravel URL. /product/{slug}/ and /product-category/{path}/ are left out – the storefront
     * resolves those itself.
     */
    private function permalinkRules(array $live): array
    {
        $links = $this->ctx->permalinks();
        $rules = [];
        $categories = $this->ctx->owned('categories')->pluck('path', 'wp_id')->all();
        foreach ($links->categories() as $termId => $source) {
            $laravel = $categories[$termId] ?? null;
            $from = $this->normalise('/'.$source.'/');
            if ($laravel !== null && $from !== null && $from !== strtolower($laravel) && $from !== 'product-category/'.strtolower($laravel) && ! isset($live[$from])) {
                $rules[$from] = '/'.$laravel.'/';
            }
        }
        $paths = DB::table('categories')->pluck('path', 'id')->all();
        $products = $this->ctx->owned('products')->where('status', 'published')->get(['wp_id', 'slug', 'primary_category_id'])->keyBy('wp_id');
        foreach ($links->products() as $wpId => $source) {
            $p = $products->get($wpId);
            if (! $p) {
                continue;
            }
            $laravel = (isset($paths[$p->primary_category_id]) ? $paths[$p->primary_category_id].'/' : 'product/').$p->slug;
            $from = $this->normalise('/'.$source.'/');
            if ($from !== null && $from !== strtolower($laravel) && $from !== 'product/'.strtolower($p->slug) && ! isset($live[$from])) {
                $rules[$from] = '/'.$laravel.'/';
            }
        }
        $posts = $this->ctx->owned('posts')->where('status', 'published')->pluck('slug', 'wp_id')->all();
        foreach ($links->posts() as $wpId => $source) {
            $from = $this->normalise('/'.$source.'/');
            if (isset($posts[$wpId]) && $from !== null && $from !== 'blog/'.strtolower($posts[$wpId]) && ! isset($live[$from])) {
                $rules[$from] = '/blog/'.$posts[$wpId].'/';
            }
        }

        return $rules;
    }

    /** Every URL path served by imported content: path => type. */
    private function livePaths(): array
    {
        $live = [];
        foreach (DB::table('pages')->where('status', 'published')->pluck('path') as $p) {
            $live[strtolower($p)] = 'page';
        }
        foreach (DB::table('categories')->pluck('path') as $p) {
            $live[strtolower($p)] = 'category';
        }
        $paths = DB::table('categories')->pluck('path', 'id')->all();
        foreach (DB::table('products')->where('status', 'published')->whereNull('deleted_at')->get(['slug', 'primary_category_id']) as $p) {
            $live[strtolower((isset($paths[$p->primary_category_id]) ? $paths[$p->primary_category_id].'/' : 'product/').$p->slug)] = 'product';
        }
        foreach (DB::table('posts')->where('status', 'published')->pluck('slug') as $s) {
            $live['blog/'.strtolower($s)] = 'post';
        }

        return $live;
    }

    /** A usable datetime or null (plugins store '0000-00-00 00:00:00' / epoch for "never"). */
    private static function date(?string $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' || str_starts_with($value, '0000') || str_starts_with($value, '1970') || strtotime($value) === false ? null : $value;
    }

    private function normalise(?string $url): ?string
    {
        if ($url === null) {
            return null;
        }
        $url = Formatter::relativeUrl($url);
        if ($url === null || ! str_starts_with($url, '/')) {
            return null;
        }
        $path = (string) parse_url($url, PHP_URL_PATH);
        $path = strtolower(trim(rawurldecode($path), "/ \t"));
        if (preg_match('/[\x00-\x1F\x7F]/', $path)) {
            return null;
        }

        return $path;
    }

    private function target(?string $data): ?string
    {
        $data = trim((string) $data);
        if ($data === '') {
            return null;
        }
        $url = Formatter::relativeUrl($data);

        return str_starts_with($url, '/') ? Formatter::withTrailingSlash($url) : $url;
    }
}
