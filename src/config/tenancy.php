<?php

return [
    'storage_driver' => 'Stancl\Tenancy\StorageDrivers\RedisStorageDriver',
    'tenant_route_namespace' => 'App\Http\Controllers',
    'exempt_domains' => [
        // 'localhost',
    ],
    'database' => [
        'based_on' => 'mysql',
        'prefix' => 'tenant',
        'suffix' => '',
    ],
    'redis' => [
        'prefix_base' => 'tenant',
        'prefixed_connections' => [
            'default',
            'cache',
        ],
    ],
    'cache' => [
        'prefix_base' => 'tenant',
    ],

    'filesystem' => [
        'suffix_base' => 'tenant',
        // Disks which should be suffixed with the suffix_base + tenant UUID.
        'disks' => [
            'local',
            // 's3',
        ],
    ],
    'server' => [
        'manager' => 'Stancl\Tenancy\ServerConfigManagers\NginxConfigManager',
        'file' => [
            'single' => true, // single file for all tenant vhosts
            'path' => '/etc/nginx/sites-available/tenants.conf',
            /*
            'single' => false,
            'path' => [
                'prefix' => '/etc/nginx/sites-available/tenants/tenant',
                'suffix' => '.conf',
                // results in: '/etc/nginx/sites-available/tenants/tenant' . $uuid . '.conf'
            ]
            */
        ]
    ]
];
