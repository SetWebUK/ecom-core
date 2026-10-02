<?php

namespace Pine\Commerce\Import\Steps;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Pine\Commerce\Import\Contracts\RedirectProvider;
use Pine\Commerce\Import\Data\ContentItem;
use Pine\Commerce\Import\Data\WpPost;
use Pine\Commerce\Import\Source\WordPressSource;
use Pine\Commerce\Import\Support\Formatter;

/**
 * Pages -> pages (hierarchical path = URL, the static front page is ''). Body via ResolvesContent (rendered page
 * builders first). System pages (shop/cart/checkout/account – WooCommerce page ids + `content.system_pages`) keep no
 * content: dedicated routes serve them. Templates: front page 'home', posts page 'blog', plus
 * `content.page_templates` (path or id => template). Published pages whose URL a redirect plugin redirected are
 * imported as drafts (WordPress never showed them).
 */
class PagesStep extends AbstractStep
{
    use Concerns\ResolvesContent;

    public function key(): string
    {
        return 'content.pages';
    }

    public function section(): string
    {
        return 'content';
    }

    protected function clear(): void
    {
        $this->ctx->owned('pages')->update(['parent_id' => null]);
        $this->ctx->owned('pages')->delete();
    }

    protected function import(): void
    {
        $posts = $this->wp->posts('page', ['publish', 'draft', 'private', 'pending']);
        $meta = $this->wp->postMeta($posts->pluck('ID')->all());
        $front = $this->ctx->site->pageOnFront;
        $postsPage = $this->ctx->site->pageForPosts;
        $byId = $posts->keyBy('ID');
        $redirectSources = $this->redirectSources();
        $templates = (array) $this->ctx->config('content.page_templates', []);
        $rendered = (bool) $this->ctx->config('seo.rendered_fallback', true);

        $pathOf = function ($post) use ($byId, $front, &$pathOf) {
            if ((int) $post->ID === $front) {
                return '';
            }
            $slug = $post->post_name !== '' ? urldecode($post->post_name) : (Str::slug($post->post_title) ?: 'page-'.$post->ID);
            $parent = $post->post_parent ? $byId->get($post->post_parent) : null;

            return ($parent && $pathOf($parent) !== '' ? $pathOf($parent).'/' : '').$slug;
        };
        $system = array_flip((array) $this->ctx->config('content.system_pages', []));
        foreach (['shop', 'cart', 'checkout', 'myaccount'] as $wooPage) {
            if (($id = $this->ctx->site->wooPages[$wooPage] ?? 0) && ($p = $byId->get($id))) {
                $system[$pathOf($p)] = true;
            }
        }

        // parents first
        $sorted = $posts->sortBy(fn ($p) => $p->post_parent ? 1 : 0)->values();
        $rows = [];
        $sources = [];
        $unpublished = [];
        foreach ($sorted as $post) {
            $m = $meta[$post->ID] ?? [];
            $path = $pathOf($post);
            $slug = $path === '' ? ($post->post_name ?: 'home') : Str::afterLast($path, '/');
            $isSystem = isset($system[$path]);
            [$content, $source] = $isSystem ? [null, 'system page (route)']
                : $this->body(new ContentItem('page', (int) $post->ID, $path, (string) $post->post_content, $m));
            $sources[$source] = ($sources[$source] ?? 0) + 1;
            $title = Formatter::decode($post->post_title);
            $status = $post->post_status === 'publish' ? 'published' : 'draft';
            if ($status === 'published' && $path !== '' && isset($redirectSources[$path])) {
                $status = 'draft';
                $unpublished[] = "/$path/ → {$redirectSources[$path]}";
            }
            $vars = ['title' => $title, 'excerpt' => Formatter::excerpt($post->post_excerpt ?: $content, 30)];
            $seo = $this->ctx->postSeo(WpPost::fromRow($post), $m, $vars);
            $renderedSeo = $isSystem || ! $rendered ? ['title' => null, 'description' => null] : $this->ctx->renderedSeo($path);

            $rows[] = [
                'wp_id' => $post->ID,
                'parent_id' => null, // set below once parents exist
                'title' => $title,
                'slug' => $slug,
                'path' => $path,
                'template' => match (true) {
                    (int) $post->ID === $front => 'home',
                    isset($templates[$path]) => $templates[$path],
                    isset($templates[(int) $post->ID]) => $templates[(int) $post->ID],
                    $postsPage && (int) $post->ID === $postsPage => 'blog',
                    default => 'default',
                },
                'content' => $content,
                'status' => $status,
                'meta_title' => $path === '' && $seo?->title === null
                    ? $renderedSeo['title'] // home: keep the exact rendered title
                    : $this->metaTitle($seo?->title, $renderedSeo, $title),
                'meta_description' => $seo?->description ?? ($status === 'published' ? $renderedSeo['description'] : null),
                'noindex' => (bool) $seo?->noindex,
                'sort_order' => (int) $post->menu_order,
                'created_at' => WordPressSource::gmt($post->post_date_gmt) ?? WordPressSource::gmt($post->post_modified_gmt) ?? $this->now(),
                'updated_at' => WordPressSource::gmt($post->post_modified_gmt) ?? $this->now(),
            ];
        }

        $this->ctx->adopt('pages', $rows, 'path');
        $map = $this->ctx->save('pages', $rows, 'wp_id', ['created_at']);
        foreach ($sorted as $post) {
            if ($post->post_parent && isset($map[$post->post_parent], $map[$post->ID])) {
                DB::table('pages')->where('id', $map[$post->ID])->update(['parent_id' => $map[$post->post_parent]]);
            }
        }

        // URL parity check
        $mismatch = 0;
        $rowsById = collect($rows)->keyBy('wp_id');
        foreach ($this->ctx->permalinks()->pages() as $wpId => $wpPath) {
            $row = $rowsById->get($wpId);
            if ($row && $row['path'] !== $wpPath) {
                $mismatch++;
                $this->ctx->warn("Page URL mismatch: WP /$wpPath/ vs Laravel /{$row['path']}/");
            }
        }
        foreach ($unpublished as $u) {
            $this->ctx->warn('Page set to draft because WordPress redirected its URL: '.$u);
        }
        $this->ctx->count('Pages', $posts->count().' ('.$posts->where('post_status', 'publish')->count().' published)',
            $this->ctx->owned('pages')->count().' ('.$this->ctx->owned('pages')->where('status', 'published')->count().' published)',
            collect($sources)->map(fn ($c, $s) => "$s $c")->implode(', ').($mismatch ? "; $mismatch URL mismatches" : ''));
    }

    /** Plain-path rules of the redirect plugins (normalised path => target): a published page they shadow becomes a draft. */
    private function redirectSources(): array
    {
        $out = [];
        foreach ($this->ctx->adapters->providers(RedirectProvider::class) as $provider) {
            foreach ($provider->redirects() as $rule) {
                if ($rule->regex || $rule->hasQuery()) {
                    continue;
                }
                $path = trim((string) parse_url($rule->from, PHP_URL_PATH), '/');
                if ($path !== '') {
                    $out[strtolower($path)] ??= $rule->to;
                }
            }
        }

        return $out;
    }
}
