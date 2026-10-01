<?php

namespace Pine\Commerce\Import\Adapters;

use Pine\Commerce\Import\Contracts\RenderedContentProvider;
use Pine\Commerce\Import\Data\ContentItem;
use Pine\Commerce\Import\Source\SiteProfile;
use Pine\Commerce\Import\Source\WordPressSource;
use Pine\Commerce\Import\Support\Formatter;

/**
 * Classic (non-builder) theme output: the main content area of the rendered page, found by the configurable
 * `commerce-import.content.selectors` – a list of ['open' => regex matching the container's opening tag,
 * 'require' => text that must occur in the page ('{id}' = WordPress id; guards against picking another post's
 * content)]. Consulted after page builders (Elementor, WPBakery) and before raw post_content.
 */
class RenderedTheme extends AbstractAdapter implements RenderedContentProvider
{
    public const DEFAULT_SELECTORS = [
        ['open' => '#<div class="page-content">#', 'require' => 'post-{id} '],
        ['open' => '#<div[^>]+class="[^"]*\bentry-content\b[^"]*"[^>]*>#', 'require' => 'post-{id} '],
    ];

    public function key(): string
    {
        return 'rendered-theme';
    }

    public function label(): string
    {
        return 'Rendered theme content';
    }

    public function priority(): int
    {
        return 30;
    }

    public function detect(SiteProfile $site, WordPressSource $wp): bool
    {
        return true;
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
        foreach ((array) ($this->config('content.selectors') ?? self::DEFAULT_SELECTORS) as $selector) {
            $require = isset($selector['require']) ? str_replace('{id}', (string) $item->wpId, $selector['require']) : null;
            if ($require !== null && ! str_contains($html, $require)) {
                continue;
            }
            if (($inner = Formatter::innerOf($html, $selector['open'])) !== null) {
                return $inner;
            }
        }

        return null;
    }
}
