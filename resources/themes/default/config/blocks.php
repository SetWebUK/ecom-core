<?php

/*
| Default theme – page templates and block schemas for the admin page builder, read by core
| Pine\Commerce\Services\Admin\PageBlocks through theme_config('blocks.*') (docs/ARCHITECTURE.md §7.7;
| field notation in PageBlocks' docblock). A child theme's config/blocks.php replaces these keys.
|   templates   extra or overridden page templates: key => ['label' => …, 'help' => …] (a new key renders the
|               theme view pages.{key} with the page's blocks)
|   schemas     template key => block schema; "home" = the home page sections of this theme, in page order
*/

$cta = fn (string $label = 'Button') => [
    'button_text' => ['text', ['label' => $label.' text']],
    'button_url' => ['link', ['label' => $label.' link']],
];

$home = [
    'usp_bar' => ['list', ['icon' => ['image', ['label' => 'Icon']], 'text' => ['text', ['label' => 'Text']]],
        ['label' => 'Selling points', 'description' => 'Short selling points under the header.', 'item' => 'point', 'max' => 8, 'title' => 'text']],
    'hero' => ['object', [
        'eyebrow' => ['text', ['label' => 'Small heading']],
        'title' => ['multiline', ['label' => 'Heading', 'help' => 'Empty = the store name.']],
        'text' => ['textarea', ['label' => 'Text']],
        ...$cta(),
        'trust_line' => ['text', ['label' => 'Trust line']],
        'image' => ['image', ['label' => 'Image']],
    ], ['label' => 'Hero banner', 'description' => 'The banner at the top of the page.']],
    'features' => ['list', ['icon' => ['image', ['label' => 'Icon']], 'title' => ['text', ['label' => 'Title']], 'text' => ['text', ['label' => 'Text']]],
        ['label' => 'Feature tiles', 'description' => 'Up to four tiles under the banner.', 'item' => 'tile', 'max' => 4, 'title' => 'title']],
    'categories' => ['object', [
        'title' => ['text', ['label' => 'Heading']],
        'tiles' => ['list', ['image' => ['image', ['label' => 'Image']], 'title' => ['text', ['label' => 'Title']], 'url' => ['link', ['label' => 'Link']]],
            ['label' => 'Category tiles', 'help' => 'Empty = the top-level categories.', 'item' => 'tile', 'max' => 12, 'title' => 'title']],
    ], ['label' => 'Categories', 'description' => 'Grid of category tiles.']],
    'best_sellers' => ['object', [
        'title' => ['text', ['label' => 'Heading']],
        'text' => ['textarea', ['label' => 'Text']],
        'product_ids' => ['ids', ['label' => 'Products to show', 'help' => 'In this order. Leave empty to show featured products (tick “Featured” on a product).']],
        'limit' => ['int', ['label' => 'Number of products', 'min' => 1, 'max' => 24]],
    ], ['label' => 'Featured products', 'description' => 'Product grid.']],
    'why' => ['object', [
        'title' => ['multiline', ['label' => 'Heading']],
        'text' => ['textarea', ['label' => 'Text', 'rows' => 6]],
        ...$cta(),
        'image' => ['image', ['label' => 'Image']],
    ], ['label' => 'Text and image', 'description' => 'Shown when it has a heading.']],
    'benefits' => ['object', [
        'title' => ['multiline', ['label' => 'Heading']],
        'text' => ['textarea', ['label' => 'Text']],
        'items' => ['list', ['icon' => ['image', ['label' => 'Icon']], 'title' => ['text', ['label' => 'Title']], 'text' => ['textarea', ['label' => 'Text']]],
            ['label' => 'Benefits', 'item' => 'benefit', 'max' => 12, 'title' => 'title']],
    ], ['label' => 'Benefits', 'description' => 'Cards on a tinted background.']],
    'brands' => ['object', [
        'title' => ['text', ['label' => 'Heading']],
        'text' => ['textarea', ['label' => 'Text']],
        'items' => ['list', ['image' => ['image', ['label' => 'Logo']], 'alt' => ['text', ['label' => 'Brand name']], 'url' => ['link', ['label' => 'Link']]],
            ['label' => 'Brands', 'item' => 'brand', 'max' => 12, 'title' => 'alt']],
    ], ['label' => 'Brands', 'description' => 'Logo strip.']],
    'faq' => ['object', [
        'title' => ['text', ['label' => 'Heading']],
        'items' => ['list', ['question' => ['text', ['label' => 'Question']], 'answer' => ['html', ['label' => 'Answer']]],
            ['label' => 'Questions', 'item' => 'question', 'max' => 40, 'title' => 'question']],
    ], ['label' => 'FAQ', 'description' => 'Questions and answers (also shown to Google as FAQ results).']],
    'cta' => ['object', [
        'title' => ['multiline', ['label' => 'Heading']],
        'text' => ['textarea', ['label' => 'Text']],
        ...$cta(),
    ], ['label' => 'Closing banner', 'description' => 'Call to action at the bottom of the page.']],
];

return [
    'templates' => [],

    'schemas' => [
        'home' => $home,
    ],
];
