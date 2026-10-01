<?php

/*
| Default theme menus. Locations are managed in Admin › Content › Menus; a location without items falls back to the
| list below (empty = hidden). Child themes override these keys in their own config/menus.php.
*/

return [
    // header navigation: first location with items wins
    'main' => ['main', 'mega'],
    // mobile drawer: first location with items wins
    'mobile' => ['mobile_nav', 'mobile', 'main'],
    // footer link columns (headed by the menu's name), up to three that have items
    'footer' => ['footer_shop', 'footer_company', 'footer_information', 'footer_why', 'footer_categories'],
    // small links next to the copyright line
    'legal' => ['footer_legal'],
    'locations' => [
        'main' => 'Header navigation',
        'mobile_nav' => 'Mobile menu',
        'footer_shop' => 'Footer column 1',
        'footer_company' => 'Footer column 2',
        'footer_information' => 'Footer column 3',
        'footer_legal' => 'Footer legal links',
    ],
    // items shown for a location that has no items: location => list of items (label, url, children …); footer
    // columns may use ['title' => …, 'items' => [...]]
    'fallbacks' => [],
    // CSS classes offered for menu items in Admin › Content › Menus (class => description)
    'style_classes' => [],
];
