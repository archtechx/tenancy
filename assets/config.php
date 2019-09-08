<?php

declare(strict_types=1);

return [
    'storage_driver' => 'Stancl\Tenancy\StorageDrivers\DatabaseStorageDriver',
    'storage' => [
        'db' => [ // Stancl\Tenancy\StorageDrivers\DatabaseStorageDriver
            'data_column' => 'data',
            'custom_columns' => [
                // 'plan',
            ],
            'connection' => 'central',
        ],
        'redis' => [ // Stancl\Tenancy\StorageDrivers\RedisStorageDriver
            'connection' => 'tenancy',
        ],
    ],
    'tenant_route_namespace' => 'App\Http\Controllers',
    'exempt_domains' => [ // e.g. domains which host landing pages, sign up pages, etc
        // 'localhost',
    ],
    'database' => [
        'based_on' => 'mysql', // The connection that will be used as a base for the dynamically created tenant connection.
        'prefix' => 'tenant',
        'suffix' => '',
    ],
    'redis' => [
        'prefix_base' => 'tenant',
        'prefixed_connections' => [
            // 'default',
            // 'cache',
        ],
    ],
    'cache' => [
        'tag_base' => 'tenant',
    ],
    'filesystem' => [ // https://stancl-tenancy.netlify.com/docs/filesystem-tenancy/
        'suffix_base' => 'tenant',
        // Disks which should be suffixed with the suffix_base + tenant UUID.
        'disks' => [
            'local',
            'public',
            // 's3',
        ],
        'root_override' => [
            // Disks whose roots should be overriden after storage_path() is suffixed.
            'local' => '%storage_path%/app/',
            'public' => '%storage_path%/app/public/',
        ],
    ],
    'database_managers' => [
        // Tenant database managers handle the creation & deletion of tenant databases.
        'sqlite' => 'Stancl\Tenancy\TenantDatabaseManagers\SQLiteDatabaseManager',
        'mysql' => 'Stancl\Tenancy\TenantDatabaseManagers\MySQLDatabaseManager',
        'pgsql' => 'Stancl\Tenancy\TenantDatabaseManagers\PostgreSQLDatabaseManager',
    ],
    'bootstrappers' => [
        'database' => 'Stancl\Tenancy\TenancyBootstrappers\DatabaseTenancyBootstrapper',
        'cache' => 'Stancl\Tenancy\TenancyBootstrappers\CacheTenancyBootstrapper',
        'filesystem' => 'Stancl\Tenancy\TenancyBootstrappers\FilesystemTenancyBootstrapper',
        'redis' => 'Stancl\Tenancy\TenancyBootstrappers\RedisTenancyBootstrapper',
    ],
    'features' => [
        // Features are classes that provide additional functionality
        // not needed for tenancy to be bootstrapped. They are run
        // regardless of whether tenancy has been initialized.
        'Stancl\Tenancy\Features\TelescopeTags',
        'Stancl\Tenancy\Features\TenantRedirect',
    ],
    'migrate_after_creation' => false, // run migrations after creating a tenant
    'queue_database_creation' => false,
    'queue_database_deletion' => false,
    'unique_id_generator' => 'Stancl\Tenancy\UUIDGenerator',
];
