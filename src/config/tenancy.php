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
        ],
        'nginx' => [
            'webroot' => '/var/www/html',
            'vhost' => "
            server {
                include includes/tenancy;
                server_name %host%;
            }",
            'extra_certbot_args' => [
                '--must-staple',
                '--config-dir', '/etc/letsencrypt_tenancy',
                '--work-dir', '/var/lib/letsencrypt_tenancy',
                '--logs-dir', '/var/log/letsencrypt_tenancy',
                '--staging', // obtains a fake cert intended for testing certbot
                // '--email', 'your@email', // if you haven't created an account in certbot yet
            ],
        ],
    ]
];
