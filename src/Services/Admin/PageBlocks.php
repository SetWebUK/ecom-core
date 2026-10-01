<?php

namespace Pine\Commerce\Services\Admin;

use Pine\Commerce\Extensions\ExtensionRegistry;
use Pine\Commerce\Http\Controllers\HomeController;
use Pine\Commerce\Models\Page;

/**
 * Structured page data ($page->blocks) edited in the page form. The schema mirrors what the storefront reads:
 *   home template  -> Pine\Commerce\Http\Controllers\HomeController; sections = the active theme's
 *                     theme config blocks.schemas.home, defaults = theme config home.defaults
 *   theme templates -> theme config blocks.templates + blocks.schemas.{key} (rendered by the theme view pages.{key})
 *   client templates -> Commerce::pageTemplate($key, $meta, $schema) (rendered by $meta['view'] or pages.{key})
 *   faq template   -> PageController::faqItems() reads blocks.faq = [{question, answer (html)}]
 *   every page     -> blocks.show_title (PageController: show the title above Elementor layouts)
 * Keys the form doesn't know are kept and edited as JSON ("Advanced data").
 *
 * Field types: text | multiline (line breaks kept) | textarea | html (trusted staff HTML) | link | image | int | bool
 *              | ids (product ids) | ['list', fields, meta] | ['object', fields, meta]
 */
class PageBlocks
{
    public const TEMPLATES = [
        'default' => ['label' => 'Standard page', 'help' => 'The content below, with the page title at the top (Elementor layouts fill the full width).'],
        'full-width' => ['label' => 'Full width', 'help' => 'Same as a standard page, for wide layouts.'],
        'contact' => ['label' => 'Contact page', 'help' => 'Your content plus the enquiry form and contact details (unless the content already has the form).'],
        'faq' => ['label' => 'FAQ page', 'help' => 'Questions and answers shown as an accordion (with Google FAQ markup) under the content.'],
        'home' => ['label' => 'Home page', 'help' => 'The shop’s home page, built from the sections below. There can only be one.'],
        'blog' => ['label' => 'Blog index', 'help' => 'The /blog/ page: your content with the [blog_index] shortcode where the post grid should appear.'],
    ];

    /**
     * Page templates offered in the admin: the core ones plus the active theme's (theme config blocks.templates, which
     * may also relabel a core template).
     *
     * @return array<string, array{label:string, help:string}>
     */
    public static function templates(): array
    {
        $templates = static::TEMPLATES;
        foreach ((array) theme_config('blocks.templates', []) as $key => $meta) {
            if (is_string($key) && preg_match('/^[a-z0-9-]+$/', $key) && is_array($meta)) {
                $templates[$key] = array_replace($templates[$key] ?? ['label' => ucfirst(str_replace('-', ' ', $key)), 'help' => ''], $meta);
            }
        }
        // client templates (Commerce::pageTemplate()) – may also relabel a core/theme template
        foreach (app(ExtensionRegistry::class)->pageTemplates() as $key => $entry) {
            $templates[$key] = array_replace($templates[$key] ?? ['label' => ucfirst(str_replace('-', ' ', $key)), 'help' => ''], $entry['meta']);
        }

        return $templates;
    }

    /** Is $template one of the core templates rather than a theme template? */
    public static function isCore(?string $template): bool
    {
        return array_key_exists((string) $template, static::TEMPLATES);
    }

    /**
     * Schema for a template: [key => type]. The active theme's schema wins (theme config blocks.schemas.{template}); the
     * home page sections are ALWAYS the theme's (the default theme ships a neutral set).
     */
    public static function schema(?string $template): array
    {
        $registered = app(ExtensionRegistry::class)->pageTemplates()[(string) $template] ?? null;
        if ($registered && $registered['schema']) {
            return $registered['schema'];
        }
        $themed = theme_config('blocks.schemas', []);
        if (is_array($themed) && is_array($themed[(string) $template] ?? null)) {
            return $themed[(string) $template];
        }

        return match ($template) {
            'home' => [],
            'faq' => ['faq' => ['list', ['question' => 'text', 'answer' => 'html'], ['label' => 'Questions', 'item' => 'question', 'max' => 60]], 'show_title' => 'bool'],
            'blog' => [],
            default => ['show_title' => 'bool'],
        };
    }

    /** Home page sections in page order, with labels for the builder (the active theme's "home" schema). */
    public static function home(): array
    {
        return static::schema('home');
    }

    /** Normalise a schema entry to [type, fields|null, meta]. */
    public static function type(mixed $spec): array
    {
        if (is_string($spec)) {
            return [$spec, null, []];
        }
        if (in_array($spec[0], ['list', 'object'], true)) {
            return [$spec[0], $spec[1], $spec[2] ?? []];
        }

        return [$spec[0], null, $spec[1] ?? []];
    }

    /** Blocks as the form should show them: home blocks merged over the live defaults, so the editor matches the site. */
    public static function forEditing(Page $page): array
    {
        $blocks = is_array($page->blocks) ? $page->blocks : [];
        if ($page->template === 'home' && class_exists(HomeController::class) && method_exists(HomeController::class, 'blocks')) {
            $blocks = array_replace($blocks, HomeController::blocks($blocks));
        }

        return $blocks;
    }

    /** Make sure every schema key exists (empty lists/objects), so the builder can bind to it. */
    public static function withSkeleton(array $blocks, ?string $template): array
    {
        foreach (static::schema($template) as $key => $spec) {
            $blocks[$key] = static::fill($blocks[$key] ?? null, $spec);
        }

        return $blocks;
    }

    protected static function fill(mixed $value, mixed $spec): mixed
    {
        [$type, $fields] = static::type($spec);

        return match ($type) {
            'list' => array_values(array_map(fn ($row) => static::fill(is_array($row) ? $row : [], ['object', $fields]), is_array($value) ? array_filter($value, 'is_array') : [])),
            'object' => (function () use ($value, $fields) {
                $value = is_array($value) ? $value : [];
                foreach ($fields as $k => $child) {
                    $value[$k] = static::fill($value[$k] ?? null, $child);
                }

                return $value;
            })(),
            'ids' => array_values(array_filter(array_map('intval', is_array($value) ? $value : []))),
            'int' => is_numeric($value) ? (int) $value : null,
            'bool' => filter_var($value, FILTER_VALIDATE_BOOL),
            default => is_scalar($value) ? (string) $value : '',
        };
    }

    /** Empty item for every list in the schema, keyed by dotted path ("categories.tiles"). */
    public static function blanks(?string $template): array
    {
        $out = [];
        $walk = function (array $fields, string $prefix) use (&$walk, &$out) {
            foreach ($fields as $key => $spec) {
                [$type, $children] = static::type($spec);
                $path = ltrim($prefix.'.'.$key, '.');
                if ($type === 'list') {
                    $out[$path] = static::fill([], ['object', $children]);
                } elseif ($type === 'object') {
                    $walk($children, $path);
                }
            }
        };
        $walk(static::schema($template), '');

        return $out;
    }

    /** Stored keys the form has no fields for (edited as JSON). */
    public static function extra(array $blocks, ?string $template): array
    {
        return array_diff_key($blocks, static::schema($template));
    }

    /** Validation rules for blocks.* of a template. */
    public static function rules(?string $template): array
    {
        $rules = ['blocks' => ['nullable', 'array']];
        foreach (static::schema($template) as $key => $spec) {
            static::fieldRules($rules, 'blocks.'.$key, $spec);
        }

        return $rules;
    }

    protected static function fieldRules(array &$rules, string $path, mixed $spec): void
    {
        [$type, $fields, $meta] = static::type($spec);
        switch ($type) {
            case 'list':
                $rules[$path] = ['nullable', 'array', 'max:'.($meta['max'] ?? 50)];
                foreach ($fields as $k => $child) {
                    static::fieldRules($rules, $path.'.*.'.$k, $child);
                }
                break;
            case 'object':
                $rules[$path] = ['nullable', 'array'];
                foreach ($fields as $k => $child) {
                    static::fieldRules($rules, $path.'.'.$k, $child);
                }
                break;
            case 'ids':
                $rules[$path] = ['nullable', 'array', 'max:48'];
                $rules[$path.'.*'] = ['nullable', 'integer', 'exists:products,id'];
                break;
            case 'int':
                $rules[$path] = ['nullable', 'integer', 'min:'.($meta['min'] ?? 0), 'max:'.($meta['max'] ?? 1000)];
                break;
            case 'bool':
                $rules[$path] = ['nullable', 'boolean'];
                break;
            case 'html':
                $rules[$path] = ['nullable', 'string', 'max:100000'];
                break;
            case 'textarea':
                $rules[$path] = ['nullable', 'string', 'max:5000'];
                break;
            case 'link':
                $rules[$path] = ['nullable', 'string', 'max:500', 'not_regex:/^\s*(javascript|data|vbscript):/i'];
                break;
            case 'image':
                $rules[$path] = ['nullable', 'string', 'max:500', 'regex:#^(uploads/|https?://|/)[^\s<>"\']*$#i'];
                break;
            default:
                $rules[$path] = ['nullable', 'string', 'max:500'];
        }
    }

    /** Clean submitted blocks: only schema keys, typed values, list items re-indexed and empty rows dropped. */
    public static function clean(array $input, ?string $template): array
    {
        $out = [];
        foreach (static::schema($template) as $key => $spec) {
            if (array_key_exists($key, $input)) {
                $out[$key] = static::cleanValue($input[$key], $spec);
            }
        }

        return $out;
    }

    protected static function cleanValue(mixed $value, mixed $spec): mixed
    {
        [$type, $fields] = static::type($spec);

        return match ($type) {
            'list' => array_values(array_filter(array_map(function ($row) use ($fields) {
                if (! is_array($row)) {
                    return null;
                }
                $item = [];
                foreach ($fields as $k => $child) {
                    $item[$k] = static::cleanValue($row[$k] ?? null, $child);
                }

                return array_filter($item, fn ($v) => $v !== '' && $v !== null && $v !== []) ? $item : null;
            }, is_array($value) ? $value : []))),
            'object' => (function () use ($value, $fields) {
                $item = [];
                foreach ($fields as $k => $child) {
                    $item[$k] = static::cleanValue(is_array($value) ? ($value[$k] ?? null) : null, $child);
                }

                return $item;
            })(),
            'ids' => array_values(array_unique(array_filter(array_map('intval', is_array($value) ? $value : [])))),
            'int' => $value === null || $value === '' ? null : (int) $value,
            'bool' => filter_var($value, FILTER_VALIDATE_BOOL),
            'html', 'textarea', 'multiline' => str_replace("\r\n", "\n", trim((string) $value)),
            default => trim((string) $value),
        };
    }
}
