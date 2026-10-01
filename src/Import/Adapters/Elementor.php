<?php

namespace Pine\Commerce\Import\Adapters;

use Pine\Commerce\Import\Contracts\RenderedContentProvider;
use Pine\Commerce\Import\Data\ContentItem;
use Pine\Commerce\Import\Source\SiteProfile;
use Pine\Commerce\Import\Source\WordPressSource;
use Pine\Commerce\Import\Support\Formatter;
use Pine\Commerce\Import\Steps\ElementorCssStep;

/**
 * Elementor stores JSON, not HTML, so page bodies come from the RENDERED page (snapshot directory, or an HTTP GET of
 * --site-url): pages = the Elementor document container (data-elementor-id = the page id); posts = the theme-builder
 * "post content" widget, else the document container. Without a rendered copy the pages step falls back to the text
 * and heading widgets in _elementor_data. With --copy-uploads the generated uploads/elementor/css/post-{id}.css files
 * are copied too (ElementorCssStep).
 */
class Elementor extends AbstractAdapter implements RenderedContentProvider
{
    public function key(): string
    {
        return 'elementor';
    }

    public function label(): string
    {
        return 'Elementor';
    }

    public function priority(): int
    {
        return 100;
    }

    public function detect(SiteProfile $site, WordPressSource $wp): bool
    {
        return $site->hasPlugin('elementor/elementor.php');
    }

    public function steps(): array
    {
        return [ElementorCssStep::class];
    }

    public function renderedHtml(ContentItem $item): ?string
    {
        if (! in_array($item->kind, ['page', 'post'], true)) {
            return null;
        }
        $html = $this->ctx->rendered->html($item->path);
        if (! $html) {
            return null;
        }
        if ($item->kind === 'post') {
            $inner = Formatter::innerOf($html, '#<div[^>]+elementor-widget-theme-post-content[^>]*>#');
            if ($inner !== null && trim(strip_tags($inner)) !== '') {
                return $inner;
            }
        }

        return Formatter::elementorDocument($html, $item->wpId);
    }
}
