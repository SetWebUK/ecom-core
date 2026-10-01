<?php

namespace Pine\Commerce\Import\Contracts;

use Pine\Commerce\Import\Data\SeoData;
use Pine\Commerce\Import\Data\WpPost;
use Pine\Commerce\Import\Data\WpTerm;

/** SEO plugin data (Rank Math, Yoast …). The first provider returning non-null wins. */
interface SeoProvider
{
    /** @param array<string,mixed> $vars template variables: title, excerpt … */
    public function postSeo(WpPost $post, array $meta, array $vars): ?SeoData;

    /** @param array<string,mixed> $vars template variables: term, term_description … ($term->meta holds the term meta) */
    public function termSeo(WpTerm $term, string $taxonomy, array $vars): ?SeoData;

    /** Primary term chosen in the SEO plugin (used for permalinks / primary category), or null. */
    public function primaryTermId(WpPost $post, array $meta, string $taxonomy): ?int;

    /** @return array<string,mixed> seo.* settings (site_name, title_separator, title_suffix, default_image, organization_logo) */
    public function siteSettings(): array;
}
