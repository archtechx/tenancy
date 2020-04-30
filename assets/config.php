<?php

declare(strict_types=1);

return [
    /**
     * Storage drivers are used to store information about your tenants.
     * They hold the Tenant Storage data and keeps track of domains.
     */
    'storage_driver' => 'db',
    'storage_drivers' => [
        /**
         * The majority of applications will want to use this storage driver.
         * The information about tenants is persisted in a relational DB
         * like MySQL or PostgreSQL. The only downside is performance.
         *
         * A database connection to the central database has to be established on each
         * request, to identify the tenant based on the domain. This takes three DB
         * queries. Then, the connection to the tenant database is established.
         *
         * Note: From v2.3.0, the performance of the DB storage driver can be improved
         * by a lot by using Cached Tenant Lookup. Be sure to enable that if you're
         * using this storage driver. Enabling that feature can completely avoid
         * querying the central database to identify build the Tenant object.
         */
        'db' => [
            'driver' => Stancl\Tenancy\StorageDrivers\Database\DatabaseStorageDriver::class,
            'data_column' => 'data',
            'custom_columns' => [
                // 'plan',
            ],

            /**
             * Your central database connection. Set to null to use the default one.
             *
             * Note: It's recommended to create a designated central connection,
             * to let you easily use it in your app, e.g. via the DB facade.
             */
            'connection' => null,

            'table_names' => [
                'tenants' => 'tenants',
                'domains' => 'domains',
            ],

            /**
             * Here you can enable the Cached Tenant Lookup.
             *
             * You can specify what cache store should be used to cache the tenant resolution.
             * Set to string with a specific cache store name, or to null to disable cache.
             */
            'cache_store' => null,
            'cache_ttl' => 3600, // seconds
        ],

        /**
         * The Redis storage driver is much more performant than the database driver.
         * However, by default, Redis is a not a durable data storage. It works well for ephemeral data
         * like cache, but to hold critical data, it needs to be configured in a way that guarantees
         * that data will be persisted permanently. Specifically, you want to enable both AOF and
         * RDB. Read this here: https://tenancy.samuelstancl.me/docs/v2/storage-drivers/#redis.
         */
        'redis' => [
            'driver' => Stancl\Tenancy\StorageDrivers\RedisStorageDriver::class,
            'connection' => 'tenancy',
        ],
    ],

    /**
     * Controller namespace used by routes in routes/tenant.php.
     */
    'tenant_route_namespace' => 'App\Http\Controllers',

    /**
     * Central domains (hostnames), e.g. domains which host landing pages, sign up pages, etc.
     */
    'exempt_domains' => [
        // 'localhost',
    ],

    /**
     * Tenancy bootstrappers are executed when tenancy is initialized.
     * Their responsibility is making Laravel features tenant-aware.
     *
     * To configure their behavior, see the config keys below.
     */
    'bootstrappers' => [
        'database' => Stancl\Tenancy\TenancyBootstrappers\DatabaseTenancyBootstrapper::class,
        'cache' => Stancl\Tenancy\TenancyBootstrappers\CacheTenancyBootstrapper::class,
        'filesystem' => Stancl\Tenancy\TenancyBootstrappers\FilesystemTenancyBootstrapper::class,
        'queue' => Stancl\Tenancy\TenancyBootstrappers\QueueTenancyBootstrapper::class,
        // 'redis' => Stancl\Tenancy\TenancyBootstrappers\RedisTenancyBootstrapper::class, // Note: phpredis is needed
    ],

    /**
     * Database tenancy config. Used by DatabaseTenancyBootstrapper.
     */
    'database' => [
        /**
         * The connection that will be used as a template for the dynamically created tenant connection.
         * Set to null to use the default connection.
         */
        'based_on' => null,

        /**
         * Tenant database names are created like this:
         * prefix + tenant_id + suffix.
         */
        'prefix' => 'tenant',
        'suffix' => '',

        'separate_by' => 'database', // database or schema (only supported by pgsql)
    ],

    /**
     * Redis tenancy config. Used by RedisTenancyBoostrapper.
     *
     * Note: You need phpredis to use Redis tenancy.
     *
     * Note: You don't need to use this if you're using Redis only for cache.
     * Redis tenancy is only relevant if you're making direct Redis calls,
     * either using the Redis facade or by injecting it as a dependency.
     */
    'redis' => [
        'prefix_base' => 'tenant', // Each key in Redis will be prepended by this prefix_base, followed by the tenant id.
        'prefixed_connections' => [ // Redis connections whose keys are prefixed, to separate one tenant's keys from another.
            // 'default',
        ],
    ],

    /**
     * Cache tenancy config. Used by CacheTenancyBootstrapper.
     *
     * This works for all Cache facade calls, cache() helper
     * calls and direct calls to injected cache stores.
     *
     * Each key in cache will have a tag applied on it. This tag is used to
     * scope the cache both when writing to it and when reading from it.
     */
    'cache' => [
        'tag_base' => 'tenant', // This tag_base, followed by the tenant_id, will form a tag that will be applied on each cache call.
    ],

    /**
     * Filesystem tenancy config. Used by FilesystemTenancyBootstrapper.
     * https://tenancy.samuelstancl.me/docs/v2/filesystem-tenancy/.
     */
    'filesystem' => [
        /**
         * Each disk listed in the 'disks' array will be suffixed by the suffix_base, followed by the tenant_id.
         */
        'suffix_base' => 'tenant',
        'disks' => [
            'local',
            'public',
            // 's3',
        ],

        /**
         * Use this for local disks.
         *
         * See https://tenancy.samuelstancl.me/docs/v2/filesystem-tenancy/
         */
        'root_override' => [
            // Disks whose roots should be overriden after storage_path() is suffixed.
            'local' => '%storage_path%/app/',
            'public' => '%storage_path%/app/public/',
        ],

        /**
         * Should storage_path() be suffixed.
         *
         * Note: Disabling this will likely break local disk tenancy. Only disable this if you're using an external file storage service like S3.
         *
         * For the vast majority of applications, this feature should be enabled. But in some
         * edge cases, it can cause issues (like using Passport with Vapor - see #196), so
         * you may want to disable this if you are experiencing these edge case issues.
         */
        'suffix_storage_path' => true,

        /**
         * By default, asset() calls are made multi-tenant too. You can use global_asset() and mix()
         * for global, non-tenant-specific assets. However, you might have some issues when using
         * packages that use asset() calls inside the tenant app. To avoid such issues, you can
         * disable asset() helper tenancy and explicitly use tenant_asset() calls in places
         * where you want to use tenant-specific assets (product images, avatars, etc).
         */
        'asset_helper_tenancy' => true,
    ],

    /**
     * TenantDatabaseManagers are classes that handle the creation & deletion of tenant databases.
     */
    'database_managers' => [
        'sqlite' => Stancl\Tenancy\TenantDatabaseManagers\SQLiteDatabaseManager::class,
        'mysql' => Stancl\Tenancy\TenantDatabaseManagers\MySQLDatabaseManager::class,
        'pgsql' => Stancl\Tenancy\TenantDatabaseManagers\PostgreSQLDatabaseManager::class,

        /**
         * Disable the pgsql manager above, enable the one below, and set the
         * tenancy.database.separate_by config key to 'schema' if you would
         * like to separate tenant DBs by schemas rather than databases.
         */
        // 'pgsql' => Stancl\Tenancy\TenantDatabaseManagers\PostgreSQLSchemaManager::class, // Separate by schema instead of database
    ],

    /**
     * Connections used by TenantDatabaseManagers. This tells, for example, the
     * MySQLDatabaseManager to use the mysql connection to create databases.
     */
    'database_manager_connections' => [
        'sqlite' => 'sqlite',
        'mysql' => 'mysql',
        'pgsql' => 'pgsql',
    ],

    /**
     * Features are classes that provide additional functionality
     * not needed for tenancy to be bootstrapped. They are run
     * regardless of whether tenancy has been initialized.
     *
     * See the documentation page for each class to
     * understand which ones you want to enable.
     */
    'features' => [
        // Stancl\Tenancy\Features\Timestamps::class, // https://tenancy.samuelstancl.me/docs/v2/features/timestamps/
        // Stancl\Tenancy\Features\TenantConfig::class, // https://tenancy.samuelstancl.me/docs/v2/features/tenant-config/
        // Stancl\Tenancy\Features\TelescopeTags::class, // https://tenancy.samuelstancl.me/docs/v2/telescope/
        // Stancl\Tenancy\Features\TenantRedirect::class, // https://tenancy.samuelstancl.me/docs/v2/features/tenant-redirect/
    ],
    'storage_to_config_map' => [ // Used by the TenantConfig feature
        // 'paypal_api_key' => 'services.paypal.api_key',
    ],

    /**
     * The URL to which users will be redirected when they try to acceess a central route on a tenant domain.
     */
    'home_url' => '/app',

    /**
     * Automatically create a database when creating a tenant.
     */
    'create_database' => true,

    /**
     * Should tenant databases be created asynchronously in a queued job.
     */
    'queue_database_creation' => false,

    /**
     * Should tenant migrations be ran after the tenant's database is created.
     */
    'migrate_after_creation' => false,
    'migration_parameters' => [
        // '--force' => true, // Set this to true to be able to run migrations in production
        // '--path' => [], // If you need to customize paths to tenant migrations
    ],

    /**
     * Should tenant databases be automatically seeded after they're created & migrated.
     */
    'seed_after_migration' => false, // should the seeder run after automatic migration
    'seeder_parameters' => [
        '--class' => 'DatabaseSeeder', // root seeder class, e.g.: 'DatabaseSeeder'
        // '--force' => true,
    ],

    /**
     * Automatically delete the tenant's database after the tenant is deleted.
     *
     * This will save space but permanently delete data which you might want to keep.
     */
    'delete_database_after_tenant_deletion' => false,

    /**
     * Should tenant databases be deleted asynchronously in a queued job.
     */
    'queue_database_deletion' => false,

    /**
     * If you don't supply an id when creating a tenant, this class will be used to generate a random ID.
     */
    'unique_id_generator' => Stancl\Tenancy\UniqueIDGenerators\UUIDGenerator::class,

    /**
     * Middleware pushed to the global middleware stack.
     */
    'global_middleware' => [
        Stancl\Tenancy\Middleware\InitializeTenancy::class,
    ],
];
