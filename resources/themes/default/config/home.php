<?php

/*
| Default theme – home page defaults, read by core HomeController through theme_config('home.*')
| (docs/ARCHITECTURE.md §7.7). Stored Page blocks (Admin › Pages › Home) are merged over `defaults`; a block
| (or field) left empty here and in the admin is simply not shown, and the view's own fallbacks apply (store name as
| the hero heading, the "featured_title" theme setting, the top-level categories as tiles).
|
| Child themes replace the whole file's keys in their own config/home.php:
|   defaults          block values (keys = the "home" schema in config/blocks.php)
|   best_sellers      legacy_wp_ids: WordPress product ids for the product grid until products are featured (migrations)
|   seo.description   meta description when the home page has none (null = setting seo.default_description)
*/

return [
    'defaults' => [
        'usp_bar' => [],
        'hero' => [
            'eyebrow' => null,
            'title' => null,
            'text' => null,
            'button_text' => null,
            'button_url' => null,
            'trust_line' => null,
            'image' => null,
        ],
        'features' => [],
        'categories' => ['title' => null, 'tiles' => []],
        'best_sellers' => ['title' => null, 'text' => null, 'limit' => 8, 'product_ids' => []],
        'why' => ['title' => null, 'text' => null, 'button_text' => null, 'button_url' => null, 'image' => null],
        'benefits' => ['title' => null, 'text' => null, 'items' => []],
        'brands' => ['title' => null, 'text' => null, 'items' => []],
        'faq' => ['title' => null, 'items' => []],
        'cta' => ['title' => null, 'text' => null, 'button_text' => null, 'button_url' => null],
    ],

    'best_sellers' => ['legacy_wp_ids' => []],

    'seo' => ['description' => null],
];
