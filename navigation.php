<?php

return [
    'Getting Started' => [
        'url'      => 'docs/getting-started',
        'children' => [
            'Installation'             => 'docs/installation',
            'Storage Drivers'          => 'docs/storage-drivers',
            'This Package vs Others'   => 'docs/difference-between-this-package-and-others',
            'Configuration'            => 'docs/configuration',
        ],
    ],
    'Usage' => [
        'url'      => 'docs/usage',
        'children' => [
            'Creating Tenants' => 'docs/creating-tenants',
            'Tenant Routes'    => 'docs/tenant-routes',
            'Tenant Storage'   => 'docs/tenant-storage',
            'Tenant Manager'   => 'docs/tenant-manager',
            'Console Commands' => 'docs/console-commands',
        ],
    ],
    'Digging Deeper' => [
        'url'      => 'docs/digging-deeper',
        'children' => [
            'Middleware Configuration'   => 'docs/middleware-configuration',
            'Custom Database Names'      => 'docs/custom-database-names',
            'Filesystem Tenancy'         => 'docs/filesystem-tenancy',
            'Jobs & Queues'              => 'docs/jobs-queues',
            'Event System'               => 'docs/event-system',
            'Tenancy Initialization'     => 'docs/tenancy-initialization',
            'Writing Storage Drivers'    => 'docs/writing-storage-drivers',
            'Development'                => 'docs/development',
        ],
    ],
    'Integrations' => [
        'children' => [
            'Horizon' => 'docs/horizon',
            'Telescope' => 'docs/telescope',
        ],
    ],
    'Tips' => [
        'children' => [
            'HTTPS Certificates' => 'docs/https-certificates',
        ],
    ],
    'Stay Updated' => 'docs/stay-updated',
    'GitHub'       => 'https://github.com/stancl/tenancy',
];
