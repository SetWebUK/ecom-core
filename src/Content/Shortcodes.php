<?php

namespace Pine\Commerce\Content;

/**
 * Content shortcode registry (docs/ARCHITECTURE.md §11, EXTENDING.md "Shortcodes").
 *
 * `PageContent::process()` expands every registered shortcode in stored page/post HTML, in registration order.
 * Core registers the generic ones (CommerceServiceProvider): `contact_form`, `blog_index`, `sitemap`
 * (alias `wp_sitemap_page`) and `order_tracking` (alias `woocommerce_order_tracking`). Clients and themes add
 * their own or alias legacy names to them:
 *
 *   Commerce::shortcode('store_hours', fn (array $atts, array $context) => view('partials.store-hours')->render());
 *   Commerce::shortcodeAlias('old_contact_form', 'contact_form');         // or config commerce.content.shortcode_aliases
 *
 * A tag is matched as `[name]` / `[name attr=value …]` (optionally wrapped in the <p> wpautop put around it) and
 * replaced by the renderer's HTML. `$patterns` are extra raw-HTML regexes the shortcode also replaces (e.g. a form
 * WordPress already rendered in its place); they run just before the tag itself.
 */
class Shortcodes
{
    /** @var array<string, array{render: callable, patterns: list<string>, unwrap: bool}> */
    protected array $shortcodes = [];

    /** @var array<string, string> alias => shortcode name */
    protected array $aliases = [];

    /**
     * @param  callable(array<string,string> $atts, array $context): string  $render
     * @param  list<string>  $patterns
     * @param  bool  $unwrap  also swallow the <p>…</p> wpautop put around a tag on its own line
     */
    public function register(string $name, callable $render, array $patterns = [], bool $unwrap = true): void
    {
        $this->shortcodes[strtolower($name)] = ['render' => $render, 'patterns' => array_values($patterns), 'unwrap' => $unwrap];
    }

    public function alias(string $alias, string $name): void
    {
        $this->aliases[strtolower($alias)] = strtolower($name);
    }

    public function has(string $name): bool
    {
        return isset($this->shortcodes[strtolower($name)]);
    }

    /** @return list<string> registered shortcode names in processing order */
    public function names(): array
    {
        return array_keys($this->shortcodes);
    }

    /** @return array<string,string> alias => name (registered + config commerce.content.shortcode_aliases) */
    public function aliases(): array
    {
        $aliases = $this->aliases;
        foreach ((array) config('commerce.content.shortcode_aliases', []) as $alias => $name) {
            $aliases[strtolower((string) $alias)] ??= strtolower((string) $name);
        }

        return $aliases;
    }

    /** Tags that render a shortcode: its own name first, then its aliases. */
    public function tagsFor(string $name): array
    {
        $name = strtolower($name);

        return array_merge([$name], array_keys(array_filter($this->aliases(), fn ($target) => $target === $name)));
    }

    /** Does $html use the shortcode (by its name or an alias)? */
    public function usedIn(string $name, ?string $html): bool
    {
        if ($html === null || $html === '') {
            return false;
        }
        foreach ($this->tagsFor($name) as $tag) {
            if (preg_match('/\['.preg_quote($tag, '/').'(?=[\s\]\/])/i', $html)) {
                return true;
            }
        }

        return false;
    }

    /** Expand every registered shortcode in $html. */
    public function render(string $html, array $context = []): string
    {
        foreach ($this->shortcodes as $name => $shortcode) {
            $render = $shortcode['render'];
            foreach ($shortcode['patterns'] as $pattern) {
                $html = preg_replace_callback($pattern, fn () => (string) $render([], $context), $html);
            }
            if (! str_contains($html, '[')) {
                continue;
            }
            $tags = implode('|', array_map(fn ($t) => preg_quote($t, '/'), $this->tagsFor($name)));
            $tag = '\[(?:'.$tags.')(?=[\s\]\/])([^\]]*)\]';
            $html = preg_replace_callback($shortcode['unwrap'] ? '/(?:<p>\s*)?'.$tag.'(?:\s*<\/p>)?/i' : '/'.$tag.'/i',
                fn ($m) => (string) $render(static::atts($m[1]), $context), $html);
        }

        return $html;
    }

    /** Parse shortcode attributes (subset of WordPress shortcode_parse_atts()). */
    public static function atts(string $text): array
    {
        $atts = [];
        preg_match_all('/([a-z_]+)\s*=\s*(?:"([^"]*)"|\'([^\']*)\'|([^\s\]]+))/i', html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8'), $m, PREG_SET_ORDER);
        foreach ($m as $a) {
            $atts[strtolower($a[1])] = $a[2] !== '' ? $a[2] : ($a[3] !== '' ? $a[3] : ($a[4] ?? ''));
        }

        return $atts;
    }
}
