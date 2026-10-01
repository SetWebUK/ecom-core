<?php

namespace Pine\Commerce\Import\Adapters;

use Pine\Commerce\Import\Contracts\BreadcrumbTermProvider;
use Pine\Commerce\Import\Contracts\RedirectProvider;
use Pine\Commerce\Import\Contracts\SeoProvider;
use Pine\Commerce\Import\Data\RedirectRule;
use Pine\Commerce\Import\Data\SeoData;
use Pine\Commerce\Import\Data\WpPost;
use Pine\Commerce\Import\Data\WpTerm;
use Pine\Commerce\Import\Source\SiteProfile;
use Pine\Commerce\Import\Source\WordPressSource;
use Pine\Commerce\Import\Support\Formatter;

/**
 * Rank Math SEO: rank_math_title/description/focus_keyword/robots post + term meta (%variables% resolved by
 * Formatter::seo), the taxonomy default title from rank-math-options-titles, primary terms
 * (rank_math_primary_{taxonomy}), site settings, and the Rank Math redirections table (disable with
 * 'rank-math:redirects' in `commerce-import.adapters.disable`).
 */
class RankMath extends AbstractAdapter implements BreadcrumbTermProvider, RedirectProvider, SeoProvider
{
    private ?array $titles = null;

    public function key(): string
    {
        return 'rank-math';
    }

    public function label(): string
    {
        return 'Rank Math SEO';
    }

    public function priority(): int
    {
        return 20;
    }

    public function detect(SiteProfile $site, WordPressSource $wp): bool
    {
        return $site->hasPlugin('seo-by-rank-math/rank-math.php');
    }

    private function titles(): array
    {
        return $this->titles ??= (array) $this->ctx->wp->option('rank-math-options-titles', []);
    }

    public function postSeo(WpPost $post, array $meta, array $vars): ?SeoData
    {
        if (! array_intersect_key($meta, array_flip(['rank_math_title', 'rank_math_description', 'rank_math_focus_keyword', 'rank_math_robots']))) {
            return null;
        }
        $robots = WordPressSource::unserialize($meta['rank_math_robots'] ?? '');

        return new SeoData(
            title: Formatter::seo($meta['rank_math_title'] ?? null, $vars),
            description: Formatter::seo($meta['rank_math_description'] ?? null, $vars),
            focusKeyword: trim((string) ($meta['rank_math_focus_keyword'] ?? '')) ?: null,
            noindex: is_array($robots) && in_array('noindex', $robots, true),
            canonical: ($meta['rank_math_canonical_url'] ?? '') ?: null,
        );
    }

    public function termSeo(WpTerm $term, string $taxonomy, array $vars): ?SeoData
    {
        $m = $term->meta;
        $default = $this->titles()['tax_'.$taxonomy.'_title'] ?? '%term% Archives %page% %sep% %sitename%';
        $robots = WordPressSource::unserialize($m['rank_math_robots'] ?? '');

        return new SeoData(
            title: Formatter::seo($m['rank_math_title'] ?? null, $vars) ?? Formatter::seo($default, $vars),
            description: Formatter::seo($m['rank_math_description'] ?? null, $vars),
            focusKeyword: trim((string) ($m['rank_math_focus_keyword'] ?? '')) ?: null,
            noindex: is_array($robots) && in_array('noindex', $robots, true),
            canonical: ($m['rank_math_canonical_url'] ?? '') ?: null,
        );
    }

    public function primaryTermId(WpPost $post, array $meta, string $taxonomy): ?int
    {
        $id = (int) ($meta['rank_math_primary_'.$taxonomy] ?? 0);

        return $id > 0 ? $id : null;
    }

    /**
     * Rank Math's breadcrumb: the primary term when it is one of the post's terms, else the first of get_the_terms() -
     * which WordPress sorts by term name (then id).
     */
    public function breadcrumbTermId(WpPost $post, array $meta, string $taxonomy, array $terms): ?int
    {
        if (! $terms) {
            return null;
        }
        $primary = $this->primaryTermId($post, $meta, $taxonomy);
        if ($primary && in_array($primary, array_map(fn ($t) => (int) $t['id'], $terms), true)) {
            return $primary;
        }
        usort($terms, fn ($a, $b) => [mb_strtolower((string) $a['name']), (int) $a['id']] <=> [mb_strtolower((string) $b['name']), (int) $b['id']]);

        return (int) $terms[0]['id'];
    }

    public function siteSettings(): array
    {
        $t = $this->titles();

        return array_filter([
            'site_name' => isset($t['website_name']) && $t['website_name'] !== '' ? Formatter::decode($t['website_name']) : null,
            'title_separator' => $t['title_separator'] ?? null,
            'default_image' => Formatter::uploadPath($t['open_graph_image'] ?? null) ?? Formatter::uploadPath($t['knowledgegraph_logo'] ?? null),
            'organization_logo' => Formatter::uploadPath($t['knowledgegraph_logo'] ?? null),
        ], fn ($v) => $v !== null);
    }

    public function redirects(): iterable
    {
        if (! $this->ctx->wp->hasTable('rank_math_redirections')) {
            return;
        }
        foreach ($this->ctx->wp->table('rank_math_redirections')->where('status', 'active')->orderBy('id')->cursor() as $r) {
            $sources = WordPressSource::unserialize($r->sources);
            foreach (is_array($sources) ? $sources : [] as $source) {
                $pattern = (string) ($source['pattern'] ?? '');
                if ($pattern === '') {
                    continue;
                }
                $comparison = (string) ($source['comparison'] ?? 'exact');
                $from = preg_match('#^(?:https?:)?//#i', $pattern) ? $pattern : '/'.ltrim($pattern, '/');
                yield new RedirectRule($from, (string) $r->url_to, (int) ($r->header_code ?: 301), 'rank-math',
                    $comparison !== 'exact', (int) ($r->hits ?? 0), $r->last_accessed ?? null);
            }
        }
    }
}
