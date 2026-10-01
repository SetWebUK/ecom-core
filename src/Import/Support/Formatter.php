<?php

namespace Pine\Commerce\Import\Support;

use Illuminate\Support\Str;

/**
 * Content transformations for imported WordPress data: wpautop(), URL rewriting to the Laravel
 * storage/relative scheme, WPBakery/Impreza shortcode flattening, SEO variable resolution and
 * extraction of rendered content blocks from rendered HTML.
 *
 * Site-specific behaviour is configured once per run by ImportContext via configure(): the hosts whose links become
 * relative (source siteurl/home + `commerce-import.legacy_hosts`), extra upload URL bases (upload_url_path/CDN),
 * the ordered search/replace map (`commerce-import.content.replace`), the shortcodes that become component
 * placeholders (`commerce-import.content.components`) and the %sitename% used by SEO templates.
 */
class Formatter
{
    /** @var list<string> hosts whose absolute links become relative (longest first) */
    private static array $hosts = [];

    /** @var list<string> absolute upload base URLs other than {host}/wp-content/uploads (e.g. a CDN), without trailing slash */
    private static array $uploadBases = [];

    /** @var array<string,string> ordered, case-insensitive search => replace applied to content and SEO strings */
    private static array $replace = [];

    /** @var array<string,string> shortcode => data-component placeholder name */
    private static array $components = ['wp_sitemap_page' => 'sitemap'];

    private static string $siteName = '';

    private const BLOCKS = '(?:table|thead|tfoot|caption|col|colgroup|tbody|tr|td|th|div|dl|dd|dt|ul|ol|li|pre|form|map|area|blockquote|address|style|p|h[1-6]|hr|fieldset|legend|section|article|aside|hgroup|header|footer|nav|figure|figcaption|details|menu|summary)';

    /**
     * @param  array{hosts?:list<string>,upload_bases?:list<string>,replace?:array<string,string>,components?:array<string,string>,site_name?:string}  $settings
     */
    public static function configure(array $settings): void
    {
        if (array_key_exists('hosts', $settings)) {
            $hosts = array_values(array_unique(array_filter(array_map(fn ($h) => strtolower(trim((string) $h)), $settings['hosts']))));
            usort($hosts, fn ($a, $b) => strlen($b) <=> strlen($a));
            self::$hosts = $hosts;
        }
        if (array_key_exists('upload_bases', $settings)) {
            self::$uploadBases = array_values(array_filter(array_map(fn ($u) => rtrim((string) $u, '/'), $settings['upload_bases'])));
        }
        if (array_key_exists('replace', $settings)) {
            self::$replace = (array) $settings['replace'];
        }
        if (array_key_exists('components', $settings)) {
            self::$components = (array) $settings['components'];
        }
        if (array_key_exists('site_name', $settings)) {
            self::$siteName = (string) $settings['site_name'];
        }
    }

    /** @return array{hosts:list<string>,upload_bases:list<string>,replace:array<string,string>,components:array<string,string>,site_name:string} */
    public static function settings(): array
    {
        return ['hosts' => self::$hosts, 'upload_bases' => self::$uploadBases, 'replace' => self::$replace,
            'components' => self::$components, 'site_name' => self::$siteName];
    }

    // ------------------------------------------------------------------ URLs

    private static function hostPattern(): string
    {
        if (! self::$hosts) {
            return '(?!)'; // matches nothing
        }

        return '(?:www\.)?(?:'.implode('|', array_map(fn ($h) => preg_quote(preg_replace('/^www\./', '', $h), '#'), self::$hosts)).')';
    }

    /** Rewrite upload URLs to /storage/uploads/ and make links to the old site relative. */
    public static function rewriteUrls(?string $html): ?string
    {
        if ($html === null || $html === '') {
            return $html;
        }
        $host = self::hostPattern();
        foreach (self::$uploadBases as $base) {
            $html = str_ireplace([$base.'/', str_replace('/', '\\/', $base).'\\/'], ['/storage/uploads/', '\\/storage\\/uploads\\/'], $html);
        }

        // JSON-escaped variants (data-settings attributes etc.)
        $html = preg_replace('#(?:https?:)?\\\\/\\\\/'.$host.'\\\\/wp-content\\\\/uploads\\\\/#i', '\\/storage\\/uploads\\/', $html);
        $html = preg_replace('#(?:https?:)?\\\\/\\\\/'.$host.'(\\\\/)?#i', '\\/', $html);

        $html = preg_replace('#(?:https?:)?//'.$host.'/wp-content/uploads/#i', '/storage/uploads/', $html);
        $html = str_replace(['"/wp-content/uploads/', "'/wp-content/uploads/", '(/wp-content/uploads/', ' /wp-content/uploads/', '(\'/wp-content/uploads/'],
            ['"/storage/uploads/', "'/storage/uploads/", '(/storage/uploads/', ' /storage/uploads/', '(\'/storage/uploads/'], $html);
        $html = preg_replace('#(?:https?:)?//'.$host.'(/|(?=["\'\s<)?\#]|$))#i', '/', $html);

        return $html;
    }

    /** Make a single URL relative to this site (or leave external / special URLs untouched). */
    public static function relativeUrl(?string $url): ?string
    {
        if ($url === null) {
            return null;
        }
        $url = trim(html_entity_decode($url, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        if ($url === '') {
            return null;
        }
        foreach (self::$uploadBases as $base) {
            if (stripos($url, $base.'/') === 0) {
                return '/storage/uploads/'.substr($url, strlen($base) + 1);
            }
        }
        $url = preg_replace('#^(?:https?:)?//'.self::hostPattern().'#i', '', $url);
        if ($url === '') {
            return '/';
        }
        if (str_starts_with($url, '/wp-content/uploads/')) {
            return '/storage/uploads/'.substr($url, strlen('/wp-content/uploads/'));
        }

        return $url;
    }

    /** A link from rendered HTML as a menu URL: relative to this site, entities decoded, WordPress trailing slash. */
    public static function menuUrl(?string $href): ?string
    {
        $url = self::relativeUrl($href);
        if ($url === null) {
            return null;
        }

        return self::withTrailingSlash(str_replace('&#038;', '&', $url));
    }

    /** WordPress URLs end with "/" – add it to bare paths (not files, queries, anchors, external). */
    public static function withTrailingSlash(string $url): string
    {
        if (! str_starts_with($url, '/') || str_starts_with($url, '//')) {
            return $url;
        }
        $parts = preg_split('/(?=[?#])/', $url, 2);
        $path = $parts[0];
        $rest = $parts[1] ?? '';
        if ($path !== '/' && ! str_ends_with($path, '/') && ! preg_match('#\.[a-z0-9]{2,5}$#i', $path)) {
            $path .= '/';
        }

        return $path.$rest;
    }

    /** "wp-content/uploads/2024/05/x.jpg" style URL/path -> "uploads/2024/05/x.jpg" (public disk path). */
    public static function uploadPath(?string $url): ?string
    {
        if (! $url) {
            return null;
        }
        if (preg_match('~/(?:wp-content|storage)/uploads/([^"\'\s?#]+)~i', $url, $m)) {
            return 'uploads/'.rawurldecode($m[1]);
        }
        foreach (self::$uploadBases as $base) {
            if (stripos($url, $base.'/') === 0 && preg_match('~^([^"\'\s?#]+)~', substr($url, strlen($base) + 1), $m)) {
                return 'uploads/'.rawurldecode($m[1]);
            }
        }

        return null;
    }

    // ------------------------------------------------------------------ HTML

    public static function stripScripts(?string $html): ?string
    {
        if ($html === null) {
            return null;
        }

        return preg_replace('#<script\b[^>]*>.*?</script>#is', '', $html);
    }

    /**
     * Apply the configured search/replace map (`commerce-import.content.replace`, ordered, case-insensitive) – e.g.
     * undo a staging search-replace ("staging.example.com" -> "example.com") left in content and SEO strings.
     */
    public static function replace(?string $value): ?string
    {
        if ($value === null || ! self::$replace) {
            return $value;
        }
        foreach (self::$replace as $search => $replacement) {
            $value = str_ireplace((string) $search, (string) $replacement, $value);
        }

        return $value;
    }

    /** @deprecated use replace() */
    public static function destage(?string $value): ?string
    {
        return self::replace($value);
    }

    /**
     * Shortcodes that printed as raw text (or must be rendered by the new site) become component placeholders the
     * Blade templates swap for real output: <div data-component="contact-form|blog-index|sitemap" …></div>.
     * The shortcode => component map is `commerce-import.content.components`.
     */
    public static function components(string $html): string
    {
        if (! self::$components) {
            return $html;
        }
        $names = array_keys(self::$components);
        usort($names, fn ($a, $b) => strlen($b) <=> strlen($a));
        $alternation = implode('|', array_map(fn ($n) => preg_quote((string) $n, '#'), $names));

        return preg_replace_callback('#(?:<p>\s*)?\[('.$alternation.')([^\]]*)\](?:\s*</p>)?#', function ($m) {
            $atts = self::shortcodeAtts($m[2]);
            $name = self::$components[$m[1]];
            $extra = isset($atts['posts_per_page']) ? ' data-per-page="'.(int) $atts['posts_per_page'].'"' : '';

            return '<div data-component="'.$name.'"'.$extra.'></div>';
        }, $html);
    }

    /** Names of the shortcodes that become components (kept by stripShortcodes()). */
    public static function componentShortcodes(): array
    {
        return array_keys(self::$components);
    }

    /**
     * Remove leftover shortcodes ([name …], [/name]) except the component shortcodes and those in $keep – used for
     * post_content of builders/plugins the importer cannot render.
     */
    public static function stripShortcodes(string $html, array $keep = []): string
    {
        $keep = array_flip(array_merge(self::componentShortcodes(), $keep));

        return preg_replace_callback('#\[(/?)([a-zA-Z][\w-]*)(?:\s[^\[\]]*)?/?\]#', function ($m) use ($keep) {
            return isset($keep[$m[2]]) ? $m[0] : '';
        }, $html);
    }

    public static function clean(?string $html): ?string
    {
        if ($html === null) {
            return null;
        }
        $html = self::components(self::replace(self::rewriteUrls(self::stripScripts($html))));
        $html = trim($html);

        return $html === '' ? null : $html;
    }

    public static function text(?string $html): string
    {
        $text = html_entity_decode(strip_tags((string) $html), ENT_QUOTES | ENT_HTML5, 'UTF-8');

        return trim(preg_replace('/\s+/u', ' ', $text));
    }

    /** First $words words of the text content (WordPress' automatic excerpt, without the "more" suffix). */
    public static function excerpt(?string $html, int $words = 55): ?string
    {
        $html = preg_replace('#<(script|style)\b[^>]*>.*?</\1>#is', '', (string) $html);
        $html = preg_replace('/\[[^\]]+\]/', '', $html);
        $text = self::text($html);
        if ($text === '') {
            return null;
        }
        $parts = preg_split('/\s+/u', $text);
        if (count($parts) <= $words) {
            return $text;
        }

        return implode(' ', array_slice($parts, 0, $words)).'…';
    }

    /**
     * Balanced extraction of the element that starts at byte offset $start (a <div|section|article…>
     * tag). Returns [outerHtml, innerHtml] or null.
     */
    public static function elementAt(string $html, int $start): ?array
    {
        if (! preg_match('#<([a-z0-9]+)\b[^>]*>#iA', $html, $m, 0, $start)) {
            return null;
        }
        $tag = strtolower($m[1]);
        $openEnd = $start + strlen($m[0]);
        $depth = 0;
        $pattern = '#<script\b.*?</script>|<style\b.*?</style>|<!--.*?-->|<'.$tag.'\b[^>]*>|</'.$tag.'\s*>#is';
        if (! preg_match_all($pattern, $html, $tokens, PREG_OFFSET_CAPTURE, $start)) {
            return null;
        }
        foreach ($tokens[0] as [$token, $offset]) {
            $lower = strtolower($token);
            if (str_starts_with($lower, '<!--') || (! in_array($tag, ['script', 'style'], true)
                && (str_starts_with($lower, '<script') || str_starts_with($lower, '<style')))) {
                continue; // opaque blocks – never count tags inside them
            }
            if (str_starts_with($lower, '</')) {
                $depth--;
                if ($depth === 0) {
                    $end = $offset + strlen($token);

                    return [substr($html, $start, $end - $start), substr($html, $openEnd, $offset - $openEnd)];
                }
            } elseif (! str_ends_with($lower, '/>')) {
                $depth++;
            }
        }

        return null;
    }

    /** Outer HTML of the Elementor document container for a post (data-elementor-id="$id"). */
    public static function elementorDocument(string $html, int $postId): ?string
    {
        if (! preg_match('#<div[^>]+data-elementor-type="(?:wp-page|wp-post)"[^>]+data-elementor-id="'.$postId.'"#', $html, $m, PREG_OFFSET_CAPTURE)) {
            return null;
        }

        return self::elementAt($html, $m[0][1])[0] ?? null;
    }

    /** Inner HTML of the first element whose opening tag matches $openTagRegex. */
    public static function innerOf(string $html, string $openTagRegex): ?string
    {
        if (! preg_match($openTagRegex, $html, $m, PREG_OFFSET_CAPTURE)) {
            return null;
        }

        return self::elementAt($html, $m[0][1])[1] ?? null;
    }

    // ------------------------------------------------------------------ wpautop

    /** Port of WordPress' wpautop(). */
    public static function autop(?string $text, bool $br = true): string
    {
        $text = (string) $text;
        if (trim($text) === '') {
            return '';
        }
        $preTags = [];
        $text .= "\n";

        if (str_contains($text, '<pre')) {
            $parts = explode('</pre>', $text);
            $last = array_pop($parts);
            $text = '';
            $i = 0;
            foreach ($parts as $part) {
                $start = strpos($part, '<pre');
                if ($start === false) {
                    $text .= $part;

                    continue;
                }
                $name = "<pre wp-pre-tag-$i></pre>";
                $preTags[$name] = substr($part, $start).'</pre>';
                $text .= substr($part, 0, $start).$name;
                $i++;
            }
            $text .= $last;
        }

        $all = self::BLOCKS;
        $text = preg_replace('|<br\s*/?>\s*<br\s*/?>|', "\n\n", $text);
        $text = preg_replace('!(<'.$all.'[\s/>])!', "\n\n$1", $text);
        $text = preg_replace('!(</'.$all.'>)!', "$1\n\n", $text);
        $text = preg_replace('!(<hr\s*?/?>)!', "$1\n\n", $text);
        $text = str_replace(["\r\n", "\r"], "\n", $text);
        // newlines inside tags are not paragraph breaks
        $text = preg_replace_callback('/<(?!!--)[^>]*>/', fn ($m) => str_replace("\n", ' <!-- wpnl --> ', $m[0]), $text);

        if (str_contains($text, '<option')) {
            $text = preg_replace('|\s*<option|', '<option', $text);
            $text = preg_replace('|</option>\s*|', '</option>', $text);
        }
        if (str_contains($text, '</object>')) {
            $text = preg_replace('|(<object[^>]*>)\s*|', '$1', $text);
            $text = preg_replace('|\s*</object>|', '</object>', $text);
            $text = preg_replace('%\s*(</?(?:param|embed)[^>]*>)\s*%', '$1', $text);
        }
        if (str_contains($text, '<source') || str_contains($text, '<track')) {
            $text = preg_replace('%([<\[](?:audio|video)[^>\]]*[>\]])\s*%', '$1', $text);
            $text = preg_replace('%\s*([<\[]/(?:audio|video)[>\]])%', '$1', $text);
            $text = preg_replace('%\s*(<(?:source|track)[^>]*>)\s*%', '$1', $text);
        }
        if (str_contains($text, '<figcaption')) {
            $text = preg_replace('|\s*(<figcaption[^>]*>)|', '$1', $text);
            $text = preg_replace('|</figcaption>\s*|', '</figcaption>', $text);
        }

        $text = preg_replace("/\n\n+/", "\n\n", $text);
        $paragraphs = preg_split('/\n\s*\n/', $text, -1, PREG_SPLIT_NO_EMPTY);
        $text = '';
        foreach ($paragraphs as $paragraph) {
            $text .= '<p>'.trim($paragraph, "\n")."</p>\n";
        }

        $text = preg_replace('|<p>\s*</p>|', '', $text);
        $text = preg_replace('!<p>([^<]+)</(div|address|form)>!', '<p>$1</p></$2>', $text);
        $text = preg_replace('!<p>\s*(</?'.$all.'[^>]*>)\s*</p>!', '$1', $text);
        $text = preg_replace('|<p>(<li.+?)</p>|', '$1', $text);
        $text = preg_replace('|<p><blockquote([^>]*)>|i', '<blockquote$1><p>', $text);
        $text = str_replace('</blockquote></p>', '</p></blockquote>', $text);
        $text = preg_replace('!<p>\s*(</?'.$all.'[^>]*>)!', '$1', $text);
        $text = preg_replace('!(</?'.$all.'[^>]*>)\s*</p>!', '$1', $text);

        if ($br) {
            $text = preg_replace_callback('/<(script|style|svg|math).*?<\/\\1>/s', fn ($m) => str_replace("\n", '<WPPreserveNewline />', $m[0]), $text);
            $text = str_replace(['<br>', '<br/>'], '<br />', $text);
            $text = preg_replace('|(?<!<br />)\s*\n|', "<br />\n", $text);
            $text = str_replace('<WPPreserveNewline />', "\n", $text);
        }

        $text = preg_replace('!(</?'.$all.'[^>]*>)\s*<br />!', '$1', $text);
        $text = preg_replace('!<br />(\s*</?(?:p|li|div|dl|dd|dt|th|pre|td|ul|ol)[^>]*>)!', '$1', $text);
        $text = preg_replace("|\n</p>$|", '</p>', $text);

        if ($preTags) {
            $text = str_replace(array_keys($preTags), array_values($preTags), $text);
        }
        if (str_contains($text, '<!-- wpnl -->')) {
            $text = str_replace([' <!-- wpnl --> ', '<!-- wpnl -->'], "\n", $text);
        }

        return trim($text);
    }

    // ------------------------------------------------------------------ WPBakery / Impreza shortcodes

    public static function hasPageBuilderShortcodes(?string $content): bool
    {
        return (bool) preg_match('/\[(vc_|us_)[a-z_]+/', (string) $content);
    }

    /** Parse shortcode attributes (subset of shortcode_parse_atts()). */
    public static function shortcodeAtts(string $text): array
    {
        $atts = [];
        $text = preg_replace("/[\x{00a0}\x{200b}]+/u", ' ', $text);
        if (preg_match_all('/([\w-]+)\s*=\s*"([^"]*)"(?:\s|$)|([\w-]+)\s*=\s*\'([^\']*)\'(?:\s|$)|([\w-]+)\s*=\s*([^\s\'"]+)(?:\s|$)/', $text, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $m) {
                if (! empty($m[1])) {
                    $atts[strtolower($m[1])] = stripcslashes($m[2]);
                } elseif (! empty($m[3])) {
                    $atts[strtolower($m[3])] = stripcslashes($m[4]);
                } elseif (! empty($m[5])) {
                    $atts[strtolower($m[5])] = stripcslashes($m[6]);
                }
            }
        }

        return $atts;
    }

    /**
     * Flatten WPBakery (vc_*) / Impreza (us_*) page-builder shortcodes into clean semantic HTML.
     * The legacy theme was switched off, so on the old site these printed as raw text.
     */
    public static function pageBuilderToHtml(string $content): string
    {
        $esc = fn ($v) => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');

        // Encoded raw HTML blocks
        $content = preg_replace_callback('#\[us_html([^\]]*)\](.*?)\[/us_html\]#s', function ($m) {
            $decoded = rawurldecode((string) base64_decode(trim($m[2]), true));

            return $decoded !== '' ? "\n\n".$decoded."\n\n" : '';
        }, $content);

        // Accordion sections -> <details>
        $content = preg_replace_callback('#\[vc_tta_section([^\]]*)\](.*?)\[/vc_tta_section\]#s', function ($m) use ($esc) {
            $atts = self::shortcodeAtts($m[1]);
            $body = self::pageBuilderToHtml($m[2]);

            return '<details class="faq-item"><summary>'.$esc($atts['title'] ?? '').'</summary><div class="faq-answer">'.$body.'</div></details>';
        }, $content);
        $content = preg_replace('#\[vc_tta_(?:accordion|tabs|tour)[^\]]*\]#', '<div class="faq-accordion">', $content);
        $content = preg_replace('#\[/vc_tta_(?:accordion|tabs|tour)\]#', '</div>', $content);

        // Text blocks
        $content = preg_replace_callback('#\[vc_column_text[^\]]*\](.*?)\[/vc_column_text\]#s', fn ($m) => '<div class="text-block">'.self::autop(trim($m[1])).'</div>', $content);

        // Icon boxes
        $content = preg_replace_callback('#\[us_iconbox([^\]]*)\](.*?)\[/us_iconbox\]#s', function ($m) use ($esc) {
            $atts = self::shortcodeAtts($m[1]);
            $title = trim($atts['title'] ?? '');
            $html = '<div class="iconbox">';
            if ($title !== '') {
                $html .= '<h3 class="iconbox-title">'.$esc($title).'</h3>';
            }

            return $html.self::autop(trim($m[2])).'</div>';
        }, $content);
        $content = preg_replace_callback('#\[us_iconbox([^\]]*)\]#', function ($m) use ($esc) {
            $atts = self::shortcodeAtts($m[1]);

            return isset($atts['title']) ? '<div class="iconbox"><h3 class="iconbox-title">'.$esc($atts['title']).'</h3></div>' : '';
        }, $content);

        // Headings / text
        $content = preg_replace_callback('#\[us_text([^\]]*)\]#', function ($m) use ($esc) {
            $atts = self::shortcodeAtts($m[1]);
            $tag = strtolower($atts['tag'] ?? 'div');
            $tag = $tag === 'h1' ? 'h2' : $tag; // the page template renders the <h1>
            if (! preg_match('/^(h[2-6]|p|div|span)$/', $tag)) {
                $tag = 'div';
            }

            return '<'.$tag.' class="page-heading">'.$esc(html_entity_decode($atts['text'] ?? '', ENT_QUOTES, 'UTF-8')).'</'.$tag.'>';
        }, $content);

        // Buttons
        $content = preg_replace_callback('#\[us_btn([^\]]*)\]#', function ($m) use ($esc) {
            $atts = self::shortcodeAtts($m[1]);
            $link = rawurldecode($atts['link'] ?? '');
            $url = '#';
            if (preg_match('/url:([^|]*)/', $link, $l)) {
                $url = $l[1];
            } elseif (str_starts_with($link, '{')) {
                $url = json_decode($link, true)['url'] ?? '#';
            }

            return '<p><a class="button" href="'.$esc(self::withTrailingSlash((string) self::relativeUrl($url))).'">'.$esc($atts['label'] ?? 'Read more').'</a></p>';
        }, $content);

        // Images
        $content = preg_replace_callback('#\[us_image([^\]]*)\]#', fn ($m) => '', $content);

        // Layout wrappers + things that have no content equivalent
        $content = preg_replace('#\[/?(?:vc_row|vc_row_inner|vc_column|vc_column_inner|vc_section)[^\]]*\]#', "\n", $content);
        $content = preg_replace('#\[(?:us_page_block|us_separator|us_breadcrumbs|vc_empty_space|vc_separator|us_post_content)[^\]]*\]#', '', $content);

        $content = trim($content);

        return str_contains($content, '<') && preg_match('#^\s*<(div|details|h\d|p)\b#', $content) ? $content : self::autop($content);
    }

    // ------------------------------------------------------------------ SEO variables

    /**
     * Resolve SEO-plugin %variables% (Rank Math %var%; Yoast %%var%% is normalised by its adapter). Unknown variables are dropped. Returns null for empty results so
     * the application default applies.
     */
    public static function seo(?string $template, array $vars = []): ?string
    {
        if ($template === null || trim($template) === '') {
            return null;
        }
        $vars += ['sep' => '|', 'sitename' => self::$siteName, 'sitedesc' => '', 'page' => '', 'currentyear' => date('Y')];
        $value = preg_replace_callback('/%([a-z_]+)(?:\([^)]*\))?%/i', fn ($m) => (string) ($vars[strtolower($m[1])] ?? ''), $template);
        $value = self::replace(html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        $value = trim(preg_replace('/\s+/u', ' ', $value));
        $value = trim(preg_replace('/^\|\s*|\s*\|$/', '', $value));

        return $value === '' ? null : $value;
    }

    public static function decode(?string $value): string
    {
        return trim(html_entity_decode((string) $value, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    }

    /** WordPress sanitize_title() equivalent for slugs. */
    public static function slug(string $value): string
    {
        return Str::slug(self::decode($value));
    }
}
