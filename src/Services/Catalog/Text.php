<?php

namespace Pine\Commerce\Services\Catalog;

/**
 * Typography helpers mirroring what WordPress did to product titles on output (wptexturize via the
 * "the_title" filter): inch marks after numbers become primes (15.6" -> 15.6″), straight quotes become
 * curly quotes, " - " becomes an en dash, "..." an ellipsis and 1920x1080 a multiplication sign.
 *
 * Returns plain (unescaped) UTF-8 text - escape it on output ({{ }}).
 */
class Text
{
    /** @var array<string,string> */
    protected static array $memo = [];

    public static function title(?string $text): string
    {
        $text = (string) $text;
        if ($text === '') {
            return '';
        }
        if (isset(static::$memo[$text])) {
            return static::$memo[$text];
        }
        $out = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');

        // Dashes / ellipsis / trademark (static + dynamic replacements of wptexturize)
        $out = str_replace(['---', ' -- ', '--', '...', ' (tm)'], ['—', ' — ', '–', '…', ' ™'], $out);
        $out = preg_replace('/(?<=\s)-(?=\s)/u', '–', $out);

        // 1920x1080 -> 1920×1080
        $out = preg_replace('/\b(\d[\d.,]*)x(\d[\d.,]*)\b/u', '$1×$2', $out);

        // Primes: 15.6" -> 15.6″ and 5' -> 5′ (only when the quote is not closing an opened quotation)
        $open = false;
        $chars = preg_split('//u', $out, -1, PREG_SPLIT_NO_EMPTY);
        $count = count($chars);
        for ($i = 0; $i < $count; $i++) {
            $c = $chars[$i];
            $prev = $i > 0 ? $chars[$i - 1] : '';
            if ($c === '"') {
                if (! $open && $prev !== '' && ctype_digit($prev)) {
                    $chars[$i] = '″';
                } elseif (! $open && ($prev === '' || preg_match('/[\s(\[{\-]/u', $prev))) {
                    $chars[$i] = '“';
                    $open = true;
                } else {
                    $chars[$i] = '”';
                    $open = false;
                }
            } elseif ($c === "'") {
                if ($prev !== '' && ctype_digit($prev)) {
                    $chars[$i] = '′';
                } elseif ($prev === '' || preg_match('/[\s(\[{\-]/u', $prev)) {
                    $chars[$i] = '‘';
                } else {
                    $chars[$i] = '’';
                }
            }
        }
        $out = implode('', $chars);

        if (count(static::$memo) > 500) {
            static::$memo = [];
        }

        return static::$memo[$text] = $out;
    }

    /**
     * WordPress wptexturize() for trusted HTML (product descriptions were output through it): curly quotes,
     * primes, en/em dashes, ellipsis and 9x9 -> 9×9 in text nodes, leaving tags, comments and
     * <pre>/<code>/<kbd>/<style>/<script>/<tt> content alone. Replacements are numeric entities, as WordPress emitted.
     */
    public static function texturize(?string $html): string
    {
        $html = (string) $html;
        if (trim($html) === '') {
            return $html;
        }
        $spaces = '[\r\n\t ]|\xC2\xA0|&nbsp;';
        $apos = '&#8217;';
        $closeQ = '&#8221;';
        $openSqFlag = '<!--osq-->';
        $openQFlag = '<!--oq-->';
        $aposFlag = '<!--apos-->';
        $cockney = ["'tain't", "'twere", "'twas", "'tis", "'twill", "'til", "'bout", "'nuff", "'round", "'cause", "'em"];
        $static = array_merge(['...', '``', "''", ' (tm)'], $cockney);
        $staticReplace = array_merge(['&#8230;', '&#8220;', $closeQ, ' &#8482;'], array_map(fn ($w) => str_replace("'", $apos, $w), $cockney));
        $aposPatterns = [
            '/\'(\d\d)\'(?=\Z|[.,:;!?)}\-\]]|&gt;|'.$spaces.')/' => $aposFlag.'$1&#8217;',
            '/\'(\d\d)"(?=\Z|[.,:;!?)}\-\]]|&gt;|'.$spaces.')/' => $aposFlag.'$1'.$closeQ,
            '/\'(?=\d\d(?:\Z|(?![%\d]|[.,]\d)))/' => $aposFlag,
            '/(?<=\A|'.$spaces.')\'(\d[.,\d]*)\'/' => $openSqFlag.'$1&#8217;',
            '/(?<=\A|[([{"\-]|&lt;|'.$spaces.')\'/' => $openSqFlag,
            '/(?<!'.$spaces.')\'(?!\Z|[.,:;!?"\'(){}[\]\-]|&[lg]t;|'.$spaces.')/' => $aposFlag,
        ];
        $quotePatterns = [
            '/(?<=\A|'.$spaces.')"(\d[.,\d]*)"/' => $openQFlag.'$1'.$closeQ,
            '/(?<=\A|[([{\-]|&lt;|'.$spaces.')"(?!'.$spaces.')/' => $openQFlag,
        ];
        $dashPatterns = [
            '/---/' => '&#8212;',
            '/(?<=^|'.$spaces.')--(?=$|'.$spaces.')/' => '&#8212;',
            '/(?<!xn)--/' => '&#8211;',
            '/(?<=^|'.$spaces.')-(?=$|'.$spaces.')/' => '&#8211;',
        ];
        $commentRegex = '!(?:-(?!->)[^\-]*+)*+(?:-->)?';
        $parts = preg_split('/(<(?(?=!--)'.$commentRegex.'|[^>]*>?))/', $html, -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY);
        if ($parts === false) {
            return $html;
        }
        $noTexturize = ['pre', 'code', 'kbd', 'style', 'script', 'tt'];
        $stack = [];
        foreach ($parts as &$part) {
            if ($part[0] === '<') {
                if (str_starts_with($part, '<!--')) {
                    continue;
                }
                // _wptexturize_pushpop_element()
                $closing = isset($part[1]) && $part[1] === '/';
                if ($closing && ! $stack) {
                    continue;
                }
                $offset = $closing ? 2 : 1;
                $space = strpos($part, ' ');
                $tag = substr($part, $offset, $space === false ? -1 : $space - $offset);
                if (in_array($tag, $noTexturize, true)) {
                    if (! $closing) {
                        $stack[] = $tag;
                    } elseif (end($stack) === $tag) {
                        array_pop($stack);
                    }
                }

                continue;
            }
            if (trim($part) === '' || $stack) {
                continue;
            }
            $part = str_replace($static, $staticReplace, $part);
            if (str_contains($part, "'")) {
                $part = preg_replace(array_keys($aposPatterns), array_values($aposPatterns), $part);
                $part = static::primes($part, "'", '&#8242;', $openSqFlag, '&#8217;', $spaces);
                $part = str_replace([$aposFlag, $openSqFlag], [$apos, '&#8216;'], $part);
            }
            if (str_contains($part, '"')) {
                $part = preg_replace(array_keys($quotePatterns), array_values($quotePatterns), $part);
                $part = static::primes($part, '"', '&#8243;', $openQFlag, $closeQ, $spaces);
                $part = str_replace($openQFlag, '&#8220;', $part);
            }
            if (str_contains($part, '-')) {
                $part = preg_replace(array_keys($dashPatterns), array_values($dashPatterns), $part);
            }
            if (preg_match('/(?<=\d)x\d/', $part) === 1) {
                $part = preg_replace('/\b(\d(?(?<=0)[\d\.,]+|[\d\.,]*))x(\d[\d\.,]*)\b/', '$1&#215;$2', $part);
            }
            $part = preg_replace('/&(?!#(?:\d+|x[a-f0-9]+);|[a-z1-4]{1,8};)/i', '&#038;', $part);
        }
        unset($part);

        return implode('', $parts);
    }

    /** wptexturize_primes(): decide whether 7' / 7" after a digit is a prime or a closing quote. */
    protected static function primes(string $haystack, string $needle, string $prime, string $openQuote, string $closeQuote, string $spaces): string
    {
        $flag = '<!--wp-prime-or-quote-->';
        $quotePattern = "/$needle(?=\\Z|[.,:;!?)}\\-\\]]|&gt;|".$spaces.')/';
        $primePattern = "/(?<=\\d)$needle/";
        $sentences = explode($openQuote, $haystack);
        foreach ($sentences as $key => &$sentence) {
            if (! str_contains($sentence, $needle)) {
                continue;
            } elseif ($key !== 0 && substr_count($sentence, $closeQuote) === 0) {
                $sentence = preg_replace($quotePattern, $flag, $sentence, -1, $count);
                if ($count > 1) {
                    $sentence = preg_replace("/(?<!\\d)$flag/", $closeQuote, $sentence, -1, $count2);
                    if ($count2 === 0) {
                        $pos = substr_count($sentence, "$flag.") > 0 ? strrpos($sentence, "$flag.") : strrpos($sentence, $flag);
                        $sentence = substr_replace($sentence, $closeQuote, $pos, strlen($flag));
                    }
                    $sentence = preg_replace($primePattern, $prime, $sentence);
                    $sentence = preg_replace("/(?<=\\d)$flag/", $prime, $sentence);
                    $sentence = str_replace($flag, $closeQuote, $sentence);
                } elseif ($count === 1) {
                    $sentence = str_replace($flag, $closeQuote, $sentence);
                    $sentence = preg_replace($primePattern, $prime, $sentence);
                } else {
                    $sentence = preg_replace($primePattern, $prime, $sentence);
                }
            } else {
                $sentence = preg_replace($primePattern, $prime, $sentence);
                $sentence = preg_replace($quotePattern, $closeQuote, $sentence);
            }
            if ($needle === '"' && str_contains($sentence, '"')) {
                $sentence = str_replace('"', $closeQuote, $sentence);
            }
        }
        unset($sentence);

        return implode($openQuote, $sentences);
    }

    /**
     * Automatic meta description of a product without an SEO description or short description - Rank Math's
     * %excerpt% fallback: the first paragraph mentioning the focus keyword, else the first paragraph, else the
     * whole text, cut at 160 characters on a word boundary (no ellipsis), as the legacy site output it.
     */
    public static function metaExcerpt(?string $html, ?string $focusKeyword = null): string
    {
        $content = preg_replace('/<!--[\s\S]*?-->/u', '', (string) $html);
        if (trim(strip_tags($content)) === '') {
            return '';
        }
        // wp_kses($content, ['p' => []]) + remove empty paragraphs
        $content = preg_replace('/<p\b[^>]*>/i', '<p>', strip_tags($content, '<p>'));
        $content = preg_replace('/<p[^>]*>(\s|&nbsp;)*<\/p>/', '', $content);

        $paragraph = null;
        $keyword = trim(explode(',', (string) $focusKeyword)[0]);
        if ($keyword !== '') {
            $pattern = str_replace([' ', '/', '(', ')', '[', ']', '{', '}', '?', '*', '+', '^', '$'], ['.', '\/', '\(', '\)', '\[', '\]', '\{', '\}', '\?', '\*', '\+', '\^', '\$'], $keyword);
            if (@preg_match_all('/<p>(.*'.$pattern.'.*)<\/p>/iu', $content, $m) && isset($m[1][0])) {
                $paragraph = $m[1][0];
            }
        }
        if ($paragraph === null) {
            $paragraph = preg_match_all('/<p>(.*)<\/p>/iu', $content, $m) && isset($m[1][0]) ? $m[1][0] : (string) $html;
        }

        // Str::truncate($text, 160)
        $text = trim(preg_replace('/[\r\n\t ]+/', ' ', strip_tags(preg_replace('#<(script|style)\b.*?</\1>#is', '', $paragraph))));
        $excerpt = preg_replace('/&[^;\s]{0,6}$/', '', mb_substr($text, 0, 160));
        if ($text !== $excerpt) {
            $cut = mb_strrpos(trim($excerpt), ' ');
            $excerpt = mb_substr($text, 0, $cut === false ? 0 : $cut);
        }

        return trim(html_entity_decode($excerpt, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    }

    /** Plain text excerpt of HTML (for meta descriptions / feeds). */
    public static function plain(?string $html, int $limit = 0): string
    {
        $text = html_entity_decode(strip_tags(preg_replace('#<(script|style)\b.*?</\1>#is', '', (string) $html)), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = trim(preg_replace('/\s+/u', ' ', $text));
        if ($limit > 0 && mb_strlen($text) > $limit) {
            $text = rtrim(mb_substr($text, 0, $limit - 1)).'…';
        }

        return $text;
    }

    /** WordPress sanitize_title() for simple ASCII labels: "Used - Like New" -> "used-like-new". */
    public static function slug(string $text): string
    {
        $text = strtolower(html_entity_decode(strip_tags($text), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        $text = preg_replace('/[^a-z0-9\s_-]+/', '', $text);
        $text = preg_replace('/[\s_]+/', '-', trim($text));

        return trim(preg_replace('/-+/', '-', $text), '-');
    }
}
