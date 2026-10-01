<?php

namespace Pine\Commerce\View\Components;

/** SEO helpers shared by the storefront controllers/views. */
class Seo
{
    /** "Page title | Site name" - Rank Math's default pattern; suffix from setting seo.title_suffix. */
    public static function title(string $title): string
    {
        $suffix = trim((string) setting('seo.title_suffix', ''));
        if ($suffix === '') {
            $suffix = '| '.setting('seo.site_name', setting('store.name', config('app.name')));
        }

        return trim($title).' '.$suffix;
    }
}
