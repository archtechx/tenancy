<?php

declare(strict_types=1);

return [
    'storage_driver' => 'Stancl\Tenancy\StorageDrivers\Database\DatabaseStorageDriver',
    'storage' => [
        'db' => [ // Stancl\Tenancy\StorageDrivers\Database\DatabaseStorageDriver
            'data_column' => 'data',
            'custom_columns' => [
                // 'plan',
            ],
            'connection' => null,
            'table_names' => [
                'TenantModel' => 'tenants',
                'DomainModel' => 'domains',
            ],
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
        // Disks which should be suffixed with the suffix_base + tenant id.
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
    'database_manager_connections' => [
        // Connections used by TenantDatabaseManagers. This tells, for example, the
        // MySQLDatabaseManager to use the mysql connection to create databases.
        'sqlite' => 'sqlite',
        'mysql' => 'mysql',
        'pgsql' => 'pgsql',
    ],
    'bootstrappers' => [
        // Tenancy bootstrappers are executed when tenancy is initialized.
        // Their responsibility is making Laravel features tenant-aware.
        'database' => 'Stancl\Tenancy\TenancyBootstrappers\DatabaseTenancyBootstrapper',
        'cache' => 'Stancl\Tenancy\TenancyBootstrappers\CacheTenancyBootstrapper',
        'filesystem' => 'Stancl\Tenancy\TenancyBootstrappers\FilesystemTenancyBootstrapper',
        'redis' => 'Stancl\Tenancy\TenancyBootstrappers\RedisTenancyBootstrapper',
        'queue' => 'Stancl\Tenancy\TenancyBootstrappers\QueueTenancyBootstrapper',
    ],
    'features' => [
        // Features are classes that provide additional functionality
        // not needed for tenancy to be bootstrapped. They are run
        // regardless of whether tenancy has been initialized.
        'Stancl\Tenancy\Features\TelescopeTags',
        'Stancl\Tenancy\Features\TenantRedirect',
    ],
    'migrate_after_creation' => false, // run migrations after creating a tenant
    'delete_database_after_tenant_deletion' => false, // delete tenant's database after deleting him
    'queue_database_creation' => false,
    'queue_database_deletion' => false,
    'unique_id_generator' => 'Stancl\Tenancy\UUIDGenerator',
];
