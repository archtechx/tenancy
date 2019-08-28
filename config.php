<?php

return [
    'baseUrl'         => '',
    'production'      => false,
    'siteName'        => 'stancl/tenancy documentation',
    'siteDescription' => 'A Laravel multi-database tenanyc package that respects your code.',

    // Algolia DocSearch credentials
    'docsearchApiKey'    => '53c5eaf88e819535d98f4a179c1802e1',
    'docsearchIndexName' => 'stancl-tenancy',

    // navigation menu
    'navigation' => require_once('navigation.php'),

    // helpers
    'isActive' => function ($page, $path) {
        return ends_with(trimPath($page->getPath()), trimPath($path));
    },
    'isActiveParent' => function ($page, $menuItem) {
        if (is_object($menuItem) && $menuItem->children) {
            return $menuItem->children->contains(function ($child) use ($page) {
                return trimPath($page->getPath()) == trimPath($child);
            });
        }
    },
    'url' => function ($page, $path) {
        return starts_with($path, 'http') ? $path : '/'.trimPath($path);
    },
];
