<?php

namespace Pine\Commerce\Content;

use DOMDocument;
use DOMElement;
use DOMXPath;
use Illuminate\Support\Collection;
use Pine\Commerce\Models\Page;
use Pine\Commerce\Models\Product;
use Pine\Commerce\Services\Catalog\ProductPresenter;
use Pine\Commerce\View\Components\PageContent;

/**
 * Pages designed in the visual builder (Admin › Pages › Design page: GrapesJS, admin/js/builder.js). A page keeps
 *   design       the builder's project data (components + styles) – only the builder reads it
 *   design_html  the page body, cleaned by cleanHtml(): no scripts, event handlers, javascript: links or foreign iframes
 *   design_css   its styles, cleaned by cleanCss(): every rule scoped to .pb-page, so a design can't restyle the
 *                header, footer or menus
 * The storefront (PageController@show → theme view pages.designed) prints render() inside <div class="pb-page">; the
 * theme's css/page-design.css styles the builder's sections, columns and buttons on the site and in the builder.
 * Shop blocks are placeholders filled in when the page is shown: [data-pb=products] → [product_grid …],
 * [data-pb=contact-form] → [contact_form].
 */
class PageDesign
{
    public const SCOPE = '.pb-page';

    /** Largest design the builder may save (bytes). */
    public const MAX_HTML = 2_000_000;

    public const MAX_CSS = 500_000;

    public const MAX_PROJECT = 8_000_000;

    /** Elements removed together with their contents. */
    protected const DROP = ['script', 'style', 'link', 'meta', 'base', 'object', 'embed', 'applet', 'frame', 'frameset', 'noscript', 'template', 'title', 'head',
        'animate', 'set', 'animatemotion', 'animatetransform', 'foreignobject', 'handler', 'listener']; // SVG animation can set links

    /** Embeds the video and map blocks make (https only); any other iframe is removed. */
    public const EMBEDS = [
        '#^https://(www\.)?youtube(-nocookie)?\.com/embed/#i',
        '#^https://player\.vimeo\.com/video/#i',
        '#^https://(maps|www)\.google\.[a-z.]+/maps#i',
    ];

    /** Attributes holding a URL. */
    protected const URL_ATTRIBUTES = ['href', 'src', 'action', 'formaction', 'poster', 'cite', 'background', 'data', 'xlink:href'];

    /** Product grid sources for the Products block. */
    public const PRODUCT_SOURCES = [
        'best-sellers' => 'Best sellers',
        'newest' => 'Newest',
        'sale' => 'On sale',
        'featured' => 'Featured',
        'category' => 'From a category',
    ];

    /** The design's HTML as customers see it: legacy URLs rewritten, shop blocks and shortcodes filled in. */
    public static function render(Page $page): string
    {
        return PageContent::expandShortcodes(PageContent::rewriteUrls((string) $page->design_html), [], false);
    }

    /** What the builder starts with for a page that has no design yet: its title and current text in one section. */
    public static function starter(Page $page): string
    {
        $content = trim(PageContent::rewriteUrls(PageContent::tidyForEditor($page->content) ?? (string) $page->content));

        return '<section class="pb-section"><div class="pb-container pb-container--narrow">'
            .'<h1>'.e($page->title).'</h1>'
            .($content === '' ? '<p>Start writing here, or drag sections in from the left.</p>' : '<div class="pb-text">'.$content.'</div>')
            .'</div></section>';
    }

    // ------------------------------------------------------------------------------------------------ HTML

    /** Staff HTML from the builder, made safe to print: see the class comment. */
    public static function cleanHtml(string $html): string
    {
        if (trim($html) === '') {
            return '';
        }
        $doc = new DOMDocument('1.0', 'UTF-8');
        $previous = libxml_use_internal_errors(true);
        $doc->loadHTML('<?xml encoding="UTF-8"><!DOCTYPE html><html><body><div id="pb-clean-root">'.$html.'</div></body></html>', LIBXML_NONET | LIBXML_COMPACT);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        $root = $doc->getElementById('pb-clean-root');
        if (! $root) {
            return '';
        }

        $images = 0;
        foreach (iterator_to_array((new DOMXPath($doc))->query('.//*', $root)) as $el) {
            /** @var DOMElement $el */
            if (! $el->parentNode) {
                continue; // inside an element removed earlier
            }
            $tag = strtolower($el->nodeName);
            if (in_array($tag, self::DROP, true) || ($tag === 'iframe' && ! static::allowedEmbed($el->getAttribute('src')))) {
                $el->parentNode->removeChild($el);

                continue;
            }
            static::cleanAttributes($el);

            if ($tag === 'iframe') {
                $el->setAttribute('loading', 'lazy');
                $el->removeAttribute('srcdoc');
            } elseif ($tag === 'img') {
                // the first picture is usually the banner at the top: load it straight away, the rest when scrolled to
                if (++$images > 1 && ! $el->hasAttribute('loading')) {
                    $el->setAttribute('loading', 'lazy');
                }
                $el->setAttribute('decoding', 'async');
            } elseif ($tag === 'a' && strtolower($el->getAttribute('target')) === '_blank') {
                $el->setAttribute('rel', 'noopener');
            }

            match ($el->getAttribute('data-pb')) {
                'products' => static::placeholder($el, 'product-grid', [
                    'source' => array_key_exists($s = $el->getAttribute('data-source'), self::PRODUCT_SOURCES) ? $s : 'best-sellers',
                    'category' => (string) (int) $el->getAttribute('data-category'),
                    'limit' => (string) max(1, min(24, (int) ($el->getAttribute('data-limit') ?: 8))),
                    'columns' => (string) max(2, min(4, (int) ($el->getAttribute('data-columns') ?: 4))),
                ]),
                'contact-form' => static::placeholder($el, 'contact-form'),
                default => null,
            };
        }

        $out = '';
        foreach ($root->childNodes as $child) {
            $out .= $doc->saveHTML($child);
        }

        return trim($out);
    }

    protected static function allowedEmbed(string $src): bool
    {
        foreach (self::EMBEDS as $pattern) {
            if (preg_match($pattern, html_entity_decode(trim($src), ENT_QUOTES | ENT_HTML5, 'UTF-8'))) {
                return true;
            }
        }

        return false;
    }

    protected static function cleanAttributes(DOMElement $el): void
    {
        foreach (iterator_to_array($el->attributes) as $attribute) {
            $name = strtolower($attribute->nodeName);
            $value = (string) $attribute->nodeValue;
            $remove = str_starts_with($name, 'on') || $name === 'srcdoc'
                || (in_array($name, self::URL_ATTRIBUTES, true) && ! static::safeUrl($value, $name === 'src' && strtolower($el->nodeName) === 'img'))
                || ($name === 'srcset' && preg_match('/(java|vb)script:|data:(?!image\/)/i', $value))
                || ($name === 'style' && ($value = static::cleanDeclarations($value)) === '');
            if ($remove) {
                $el->removeAttribute($attribute->nodeName);
            } elseif ($name === 'style') {
                $el->setAttribute('style', $value);
            }
        }
    }

    /** Relative URLs, http(s), mailto: and tel: – plus data: images where a picture is expected. */
    protected static function safeUrl(string $url, bool $image = false): bool
    {
        $url = preg_replace('/[\x00-\x20]+/', '', html_entity_decode($url, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        if (! preg_match('/^([a-z][a-z0-9+.-]*):/i', $url, $m)) {
            return true; // relative: /about/, #top, ?page=2, image.jpg
        }

        return in_array(strtolower($m[1]), ['http', 'https', 'mailto', 'tel'], true)
            || ($image && preg_match('#^data:image/(png|jpe?g|gif|webp|avif|svg\+xml)[;,]#i', $url)); // pictures can't run SVG scripts
    }

    /** Turn a shop block into its storefront placeholder (kept inside the block, which keeps its own styling). */
    protected static function placeholder(DOMElement $el, string $component, array $data = []): void
    {
        while ($el->firstChild) {
            $el->removeChild($el->firstChild);
        }
        $placeholder = $el->ownerDocument->createElement('div');
        $placeholder->setAttribute('data-component', $component);
        foreach ($data as $key => $value) {
            $placeholder->setAttribute('data-'.$key, $value);
        }
        $el->appendChild($placeholder);
    }

    // ------------------------------------------------------------------------------------------------ CSS

    /** The builder's CSS made safe to print in a <style> tag, every rule scoped to .pb-page. */
    public static function cleanCss(string $css): string
    {
        $css = preg_replace('#/\*.*?\*/#s', '', $css);
        $css = str_ireplace(['</style', '<!--', '-->'], '', $css);
        $css = preg_replace('/@(import|charset|namespace)\b[^;{}]*;?/i', '', $css);

        return trim(static::scopeRules($css));
    }

    protected static function scopeRules(string $css): string
    {
        $out = '';
        $i = 0;
        while (($open = strpos($css, '{', $i)) !== false) {
            $prelude = trim(substr($css, $i, $open - $i));
            $close = static::matchingBrace($css, $open);
            if ($close === null) {
                break; // unbalanced: drop the rest
            }
            $body = substr($css, $open + 1, $close - $open - 1);
            $i = $close + 1;
            if ($prelude === '') {
                continue;
            }
            if ($prelude[0] === '@') {
                $name = strtolower((string) preg_split('/[\s(]/', substr($prelude, 1), 2)[0]);
                if (in_array($name, ['media', 'supports', 'container'], true)) {
                    $inner = static::scopeRules($body);
                    $out .= $inner === '' ? '' : $prelude.'{'.$inner.'}';
                } elseif (in_array($name, ['keyframes', '-webkit-keyframes'], true) && ! static::dangerous($body)) {
                    $out .= $prelude.'{'.$body.'}';
                } elseif ($name === 'font-face' && ($decls = static::cleanDeclarations($body)) !== '') {
                    $out .= '@font-face{'.$decls.'}';
                }

                continue; // other at-rules (@page, @property …) are dropped
            }
            $decls = static::cleanDeclarations($body);
            if ($decls !== '') {
                $out .= static::scopeSelector($prelude).'{'.$decls.'}';
            }
        }

        return $out;
    }

    protected static function matchingBrace(string $css, int $open): ?int
    {
        $depth = 0;
        $quote = null;
        for ($i = $open, $len = strlen($css); $i < $len; $i++) {
            $c = $css[$i];
            if ($quote !== null) {
                if ($c === '\\') {
                    $i++;
                } elseif ($c === $quote) {
                    $quote = null;
                }
            } elseif ($c === '"' || $c === "'") {
                $quote = $c;
            } elseif ($c === '{') {
                $depth++;
            } elseif ($c === '}' && --$depth === 0) {
                return $i;
            }
        }

        return null;
    }

    /** "body, #a:hover, .x .y" -> ".pb-page, .pb-page #a:hover, .pb-page .x .y" (commas inside :is() kept). */
    protected static function scopeSelector(string $prelude): string
    {
        $parts = [];
        $depth = 0;
        $buffer = '';
        foreach (str_split($prelude) as $c) {
            $depth += match ($c) { '(', '[' => 1, ')', ']' => -1, default => 0 };
            if ($c === ',' && $depth === 0) {
                $parts[] = $buffer;
                $buffer = '';
            } else {
                $buffer .= $c;
            }
        }
        $parts[] = $buffer;

        return implode(', ', array_map(function (string $selector) {
            $selector = trim(preg_replace('/\s+/', ' ', $selector));
            // the page itself: the builder writes the page background as body { … }; "body #x" is inside it, "body.x" is it
            if (preg_match('/^((?:(?:html|body|:root)\b\s*)+)(.*)$/is', $selector, $m)) {
                return self::SCOPE.($m[2] === '' ? '' : (preg_match('/\s$/', $m[1]) && ! str_starts_with($m[2], '>') ? ' ' : '').$m[2]);
            }

            return self::SCOPE.' '.$selector;
        }, array_filter($parts, fn ($p) => trim($p) !== '')));
    }

    /** "color:red; background:url(javascript:…)" -> "color:red" */
    public static function cleanDeclarations(string $body): string
    {
        // split on ; outside brackets (data: URIs in url() contain semicolons)
        preg_match_all('/(?:[^;()]|\((?:[^()]|\([^()]*\))*\))+/', $body, $m);
        $kept = [];
        foreach ($m[0] as $declaration) {
            $declaration = trim($declaration);
            if ($declaration === '' || ! str_contains($declaration, ':') || static::dangerous($declaration)) {
                continue;
            }
            preg_match_all('/url\(\s*([\'"]?)(.*?)\1\s*\)/i', $declaration, $urls);
            foreach ($urls[2] as $url) {
                if (! static::safeUrl($url, true) || preg_match('/^(mailto|tel):/i', $url)) {
                    continue 2;
                }
            }
            $kept[] = $declaration;
        }

        return implode(';', $kept);
    }

    protected static function dangerous(string $css): bool
    {
        return (bool) preg_match('/expression\s*\(|(java|vb)script\s*:|behavior\s*:|-moz-binding|@import|</i', $css);
    }

    // ------------------------------------------------------------------------------------------------ shop blocks

    /** Products for the Products block ([product_grid source=… category=… limit=…]), newest stock first where it matters. */
    public static function products(array $atts): Collection
    {
        $limit = max(1, min(24, (int) ($atts['limit'] ?? 8)));
        $query = Product::query()->published()->with(ProductPresenter::CARD_RELATIONS);

        return match ($atts['source'] ?? 'best-sellers') {
            'newest' => $query->inStock()->orderByDesc('published_at')->orderByDesc('id')->limit($limit)->get(),
            'featured' => $query->where('is_featured', true)->orderBy('sort_order')->orderByDesc('id')->limit($limit)->get(),
            'sale' => $query->inStock()->whereNotNull('sale_price')->whereColumn('sale_price', '<', 'regular_price')
                ->orderByDesc('published_at')->orderByDesc('id')->limit($limit * 3)->get()
                ->filter(fn (Product $p) => $p->isOnSale())->take($limit)->values(),
            'category' => $query->inStock()->whereHas('categories', fn ($q) => $q->whereKey((int) ($atts['category'] ?? 0)))
                ->orderBy('sort_order')->orderByDesc('total_sales')->orderByDesc('id')->limit($limit)->get(),
            default => $query->inStock()->orderByDesc('total_sales')->orderByDesc('id')->limit($limit)->get(),
        };
    }
}
