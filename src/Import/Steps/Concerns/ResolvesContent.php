<?php

namespace Pine\Commerce\Import\Steps\Concerns;

use Illuminate\Support\Str;
use Pine\Commerce\Import\Contracts\ContentTransformer;
use Pine\Commerce\Import\Contracts\RenderedContentProvider;
use Pine\Commerce\Import\Data\ContentItem;
use Pine\Commerce\Import\Source\WordPressSource;
use Pine\Commerce\Import\Support\Formatter;

/**
 * Body HTML of a page/post: the first RenderedContentProvider answer (Elementor document, WPBakery conversion, theme
 * content area …, by priority), else Elementor's own data (text/heading widgets), else post_content through the
 * ContentTransformers, with leftover shortcodes stripped (`commerce-import.content.strip_shortcodes`) and wpautop.
 * Everything is finally cleaned (URLs rewritten, scripts removed, component shortcodes replaced).
 *
 * @property \Pine\Commerce\Import\ImportContext $ctx
 */
trait ResolvesContent
{
    /** @return array{0:?string,1:string} [html, source label] */
    protected function body(ContentItem $item): array
    {
        foreach ($this->ctx->adapters->providers(RenderedContentProvider::class) as $provider) {
            $html = $provider->renderedHtml($item);
            if ($html !== null) {
                return [Formatter::clean($html), $provider->label()];
            }
        }
        $meta = $item->meta;
        if (($meta['_elementor_edit_mode'] ?? '') === 'builder' && ! empty($meta['_elementor_data'])) {
            $text = $this->elementorText(WordPressSource::unserialize($meta['_elementor_data']));
            if ($text !== '') {
                return [Formatter::clean($text), 'Elementor data (no rendered copy)'];
            }
        }
        $raw = $item->html;
        foreach ($this->ctx->adapters->providers(ContentTransformer::class) as $transformer) {
            $raw = $transformer->transform($raw, $item);
        }
        if ($this->ctx->config('content.strip_shortcodes', true)) {
            $raw = Formatter::stripShortcodes($raw, (array) $this->ctx->config('content.keep_shortcodes', []));
        }

        return [Formatter::clean(Formatter::autop($raw)), 'post_content'];
    }

    /** Last resort for Elementor pages without a rendered copy: concatenate text/heading/html widgets. */
    protected function elementorText($json): string
    {
        $data = is_string($json) ? json_decode($json, true) : $json;
        if (! is_array($data)) {
            return '';
        }
        $html = '';
        $walk = function (array $elements) use (&$walk, &$html) {
            foreach ($elements as $el) {
                $s = $el['settings'] ?? [];
                switch ($el['widgetType'] ?? '') {
                    case 'heading':
                        $tag = in_array($s['header_size'] ?? 'h2', ['h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'p'], true) ? $s['header_size'] ?? 'h2' : 'h2';
                        $html .= '<'.($tag === 'h1' ? 'h2' : $tag).'>'.e(strip_tags($s['title'] ?? '')).'</'.($tag === 'h1' ? 'h2' : $tag).'>';
                        break;
                    case 'text-editor':
                        $html .= Formatter::autop($s['editor'] ?? '');
                        break;
                    case 'html':
                        $html .= $s['html'] ?? '';
                        break;
                }
                if (! empty($el['elements'])) {
                    $walk($el['elements']);
                }
            }
        };
        $walk($data);

        return $html;
    }

    /**
     * SEO title: the SEO plugin's value, else the title WordPress actually rendered when it differs from the default
     * "Title | Site name" pattern (which the storefront produces itself), else null.
     */
    protected function metaTitle(?string $value, array $rendered, string $title): ?string
    {
        if ($value === null && $rendered['title'] && $rendered['title'] !== Formatter::seo('%title% %sep% %sitename%', ['title' => $title])) {
            $value = $rendered['title'];
        }

        return $value !== null ? Str::limit($value, 250, '') : null;
    }
}
