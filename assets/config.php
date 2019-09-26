<?php

declare(strict_types=1);

return [
    'storage_driver' => Stancl\Tenancy\StorageDrivers\Database\DatabaseStorageDriver::class,
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
        'based_on' => null, // The connection that will be used as a base for the dynamically created tenant connection.
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
    'filesystem' => [ // https://tenancy.samuelstancl.me/docs/v2/filesystem-tenancy/
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
        'sqlite' => Stancl\Tenancy\TenantDatabaseManagers\SQLiteDatabaseManager::class,
        'mysql' => Stancl\Tenancy\TenantDatabaseManagers\MySQLDatabaseManager::class,
        'pgsql' => Stancl\Tenancy\TenantDatabaseManagers\PostgreSQLDatabaseManager::class,
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
        'database' => Stancl\Tenancy\TenancyBootstrappers\DatabaseTenancyBootstrapper::class,
        'cache' => Stancl\Tenancy\TenancyBootstrappers\CacheTenancyBootstrapper::class,
        'filesystem' => Stancl\Tenancy\TenancyBootstrappers\FilesystemTenancyBootstrapper::class,
        'redis' => Stancl\Tenancy\TenancyBootstrappers\RedisTenancyBootstrapper::class,
        'queue' => Stancl\Tenancy\TenancyBootstrappers\QueueTenancyBootstrapper::class,
    ],
    'features' => [
        // Features are classes that provide additional functionality
        // not needed for tenancy to be bootstrapped. They are run
        // regardless of whether tenancy has been initialized.
        Stancl\Tenancy\Features\TelescopeTags::class,
        Stancl\Tenancy\Features\TenantRedirect::class,
    ],
    'home_url' => '/app',
    'migrate_after_creation' => false, // run migrations after creating a tenant
    'delete_database_after_tenant_deletion' => false, // delete the tenant's database after deleting the tenant
    'queue_database_creation' => false,
    'queue_database_deletion' => false,
    'unique_id_generator' => Stancl\Tenancy\UUIDGenerator::class,
];
