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
            'Application Testing'        => 'docs/application-testing',
            'Writing Storage Drivers'    => 'docs/writing-storage-drivers',
            'Development'                => 'docs/development',
        ],
    ],
    'Integrations' => [
        'url'      => 'docs/integrations',
        'children' => [
            'Telescope' => 'docs/telescope',
            'Horizon'   => 'docs/horizon',
        ],
    ],
    'Tips' => [
        'children' => [
            'HTTPS Certificates' => 'docs/https-certificates',
            'Misc'               => 'docs/misc-tips',
        ],
    ],
    'Stay Updated' => 'docs/stay-updated',
    'GitHub'       => 'https://github.com/stancl/tenancy',
];
