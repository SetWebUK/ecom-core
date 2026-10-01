<?php

namespace Pine\Commerce\Import\Adapters;

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
 * Yoast SEO (free/premium): _yoast_wpseo_title/metadesc/focuskw/meta-robots-noindex post meta, term SEO from the
 * option wpseo_taxonomy_meta (+ the taxonomy default title from wpseo_titles), primary terms
 * (_yoast_wpseo_primary_{taxonomy}), site settings and Premium redirects (wpseo-premium-redirects-base).
 * Yoast %%variables%% are converted to the %variable% form Formatter::seo() resolves.
 */
class Yoast extends AbstractAdapter implements RedirectProvider, SeoProvider
{
    private const SEPARATORS = ['sc-dash' => '-', 'sc-ndash' => '–', 'sc-mdash' => '—', 'sc-colon' => ':', 'sc-middot' => '·',
        'sc-bull' => '•', 'sc-star' => '*', 'sc-smstar' => '⋆', 'sc-pipe' => '|', 'sc-tilde' => '~', 'sc-laquo' => '«',
        'sc-raquo' => '»', 'sc-lt' => '<', 'sc-gt' => '>'];

    private const VARS = ['term_title' => 'term', 'category' => 'term', 'term_description' => 'term_description',
        'category_description' => 'term_description', 'tag_description' => 'term_description', 'excerpt_only' => 'excerpt',
        'currentyear' => 'currentyear', 'sitename' => 'sitename', 'sitedesc' => 'sitedesc', 'sep' => 'sep', 'page' => 'page',
        'title' => 'title', 'excerpt' => 'excerpt'];

    private ?array $titles = null;

    private ?array $taxonomyMeta = null;

    public function key(): string
    {
        return 'yoast';
    }

    public function label(): string
    {
        return 'Yoast SEO';
    }

    public function priority(): int
    {
        return 15;
    }

    public function detect(SiteProfile $site, WordPressSource $wp): bool
    {
        return $site->hasPlugin('wordpress-seo/wp-seo.php', 'wordpress-seo-premium/wp-seo-premium.php', 'wordpress-seo-premium/*.php');
    }

    /** Yoast %%var%% → %var% with Yoast variable names mapped to the importer's. */
    public static function template(?string $template): ?string
    {
        if ($template === null || trim($template) === '') {
            return null;
        }

        return preg_replace_callback('/%%([a-z_]+)%%/i', fn ($m) => '%'.(self::VARS[strtolower($m[1])] ?? strtolower($m[1])).'%', $template);
    }

    private function titles(): array
    {
        return $this->titles ??= (array) $this->ctx->wp->option('wpseo_titles', []);
    }

    private function vars(array $vars): array
    {
        $sep = self::SEPARATORS[$this->titles()['separator'] ?? 'sc-dash'] ?? '-';

        return $vars + ['sep' => $sep];
    }

    public function postSeo(WpPost $post, array $meta, array $vars): ?SeoData
    {
        if (! array_intersect_key($meta, array_flip(['_yoast_wpseo_title', '_yoast_wpseo_metadesc', '_yoast_wpseo_focuskw', '_yoast_wpseo_meta-robots-noindex']))) {
            return null;
        }
        $vars = $this->vars($vars);

        return new SeoData(
            title: Formatter::seo(self::template($meta['_yoast_wpseo_title'] ?? null), $vars),
            description: Formatter::seo(self::template($meta['_yoast_wpseo_metadesc'] ?? null), $vars),
            focusKeyword: trim((string) ($meta['_yoast_wpseo_focuskw'] ?? '')) ?: null,
            noindex: ($meta['_yoast_wpseo_meta-robots-noindex'] ?? '') === '1',
            canonical: ($meta['_yoast_wpseo_canonical'] ?? '') ?: null,
        );
    }

    public function termSeo(WpTerm $term, string $taxonomy, array $vars): ?SeoData
    {
        $this->taxonomyMeta ??= (array) $this->ctx->wp->option('wpseo_taxonomy_meta', []);
        $m = (array) ($this->taxonomyMeta[$taxonomy][$term->id] ?? []);
        $vars = $this->vars($vars);
        $default = self::template($this->titles()['title-tax-'.$taxonomy] ?? null);

        return new SeoData(
            title: Formatter::seo(self::template($m['wpseo_title'] ?? null), $vars) ?? Formatter::seo($default, $vars),
            description: Formatter::seo(self::template($m['wpseo_desc'] ?? null), $vars)
                ?? Formatter::seo(self::template($this->titles()['metadesc-tax-'.$taxonomy] ?? null), $vars),
            focusKeyword: trim((string) ($m['wpseo_focuskw'] ?? '')) ?: null,
            noindex: ($m['wpseo_noindex'] ?? '') === 'noindex',
            canonical: ($m['wpseo_canonical'] ?? '') ?: null,
        );
    }

    public function primaryTermId(WpPost $post, array $meta, string $taxonomy): ?int
    {
        $id = (int) ($meta['_yoast_wpseo_primary_'.$taxonomy] ?? 0);

        return $id > 0 ? $id : null;
    }

    public function siteSettings(): array
    {
        $t = $this->titles();
        $name = $t['website_name'] ?? ((array) $this->ctx->wp->option('wpseo', []))['website_name'] ?? null;

        return array_filter([
            'site_name' => $name ? Formatter::decode($name) : null,
            'title_separator' => isset($t['separator']) ? (self::SEPARATORS[$t['separator']] ?? null) : null,
            'default_image' => Formatter::uploadPath(((array) $this->ctx->wp->option('wpseo_social', []))['og_default_image'] ?? null)
                ?? Formatter::uploadPath($t['company_logo'] ?? null),
            'organization_logo' => Formatter::uploadPath($t['company_logo'] ?? null),
        ], fn ($v) => $v !== null);
    }

    public function redirects(): iterable
    {
        foreach (['wpseo-premium-redirects-base' => null, 'wpseo-premium-redirects-export-plain' => 'plain'] as $option => $format) {
            $list = $this->ctx->wp->option($option, []);
            if (! is_array($list) || ! $list) {
                continue;
            }
            foreach ($list as $key => $r) {
                $r = (array) $r;
                $origin = (string) ($r['origin'] ?? (is_string($key) ? $key : ''));
                if ($origin === '') {
                    continue;
                }
                $from = preg_match('#^(?:https?:)?//#i', $origin) ? $origin : '/'.ltrim($origin, '/');
                yield new RedirectRule($from, (string) ($r['url'] ?? ''), (int) ($r['type'] ?? 301), 'yoast',
                    ($r['format'] ?? $format ?? 'plain') === 'regex');
            }

            return; // the base option is authoritative when present
        }
    }
}
