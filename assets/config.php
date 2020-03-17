<?php

declare(strict_types=1);

return [
    'storage_driver' => 'db',
    'storage_drivers' => [
        'db' => [
            'driver' => Stancl\Tenancy\StorageDrivers\Database\DatabaseStorageDriver::class,
            'data_column' => 'data',
            'custom_columns' => [
                // 'plan',
            ],
            'connection' => null, // Your central database connection. Set to null to use the default connection.
            'table_names' => [
                'tenants' => 'tenants',
                'domains' => 'domains',
            ],
            'cache_store' => null, // What store should be used to cache tenant resolution. Set to null to disable cache or a string with a specific cache store name.
            'cache_ttl' => 3600, // seconds
        ],
        'redis' => [
            'driver' => Stancl\Tenancy\StorageDrivers\RedisStorageDriver::class,
            'connection' => 'tenancy',
        ],
    ],
    'tenant_route_namespace' => 'App\Http\Controllers',
    'exempt_domains' => [ // e.g. domains which host landing pages, sign up pages, etc
        // 'localhost',
    ],
    'database' => [
        'based_on' => null, // The connection that will be used as a base for the dynamically created tenant connection. Set to null to use the default connection.
        'prefix' => 'tenant',
        'suffix' => '',
        'separate_by' => 'database', // database or schema (only supported by pgsql)
    ],
    'redis' => [
        'prefix_base' => 'tenant',
        'prefixed_connections' => [
            // 'default',
        ],
    ],
    'cache' => [
        'tag_base' => 'tenant',
    ],
    'filesystem' => [ // https://tenancy.samuelstancl.me/docs/v2/filesystem-tenancy/
        'suffix_base' => 'tenant',
        'suffix_storage_path' => true, // Note: Disabling this will likely break local disk tenancy. Only disable this if you're using an external file storage service like S3.
        'asset_helper_tenancy' => true, // should asset() be automatically tenant-aware. You may want to disable this if you use tools like Horizon.
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
        // 'pgsql' => Stancl\Tenancy\TenantDatabaseManagers\PostgreSQLSchemaManager::class, // Separate by schema instead of database
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
        'queue' => Stancl\Tenancy\TenancyBootstrappers\QueueTenancyBootstrapper::class,
        // 'redis' => Stancl\Tenancy\TenancyBootstrappers\RedisTenancyBootstrapper::class, // Note: phpredis is needed
    ],
    'features' => [
        // Features are classes that provide additional functionality
        // not needed for tenancy to be bootstrapped. They are run
        // regardless of whether tenancy has been initialized.

        // Stancl\Tenancy\Features\Timestamps::class,
        // Stancl\Tenancy\Features\TenantConfig::class,
        // Stancl\Tenancy\Features\TelescopeTags::class,
        // Stancl\Tenancy\Features\TenantRedirect::class,
    ],
    'storage_to_config_map' => [ // Used by the TenantConfig feature
        // 'paypal_api_key' => 'services.paypal.api_key',
    ],
    'home_url' => '/app',
    'create_database' => true,
    'queue_database_creation' => false,
    'migrate_after_creation' => false, // run migrations after creating a tenant
    'migration_parameters' => [
        // '--force' => true, // force database migrations
    ],
    'seed_after_migration' => false, // should the seeder run after automatic migration
    'seeder_parameters' => [
        '--class' => 'DatabaseSeeder', // root seeder class, e.g.: 'DatabaseSeeder'
        // '--force' => true, // force database seeder
    ],
    'queue_database_deletion' => false,
    'delete_database_after_tenant_deletion' => false, // delete the tenant's database after deleting the tenant
    'unique_id_generator' => Stancl\Tenancy\UniqueIDGenerators\UUIDGenerator::class,
    'global_middleware' => [
        Stancl\Tenancy\Middleware\InitializeTenancy::class,
    ],
];
