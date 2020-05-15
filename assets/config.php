<?php

declare(strict_types=1);

use Stancl\Tenancy\Database\Models\Domain;
use Stancl\Tenancy\Database\Models\Tenant;

return [
    'tenant_model' => Tenant::class,
    'domain_model' => Domain::class,
    'internal_prefix' => 'tenancy_',

    'central_connection' => 'central',
    'template_tenant_connection' => null,

    'id_generator' => Stancl\Tenancy\UniqueIDGenerators\UUIDGenerator::class,

    'custom_columns' => [
        // 
    ],

    'central_domains' => [
        '127.0.0.1',
        'localhost',
    ],

    /**
     * Controller namespace used by routes in routes/tenant.php.
     */
    'tenant_route_namespace' => 'App\Http\Controllers',

    /**
     * Tenancy bootstrappers are executed when tenancy is initialized.
     * Their responsibility is making Laravel features tenant-aware.
     *
     * To configure their behavior, see the config keys below.
     */
    'bootstrappers' => [
        'database' => Stancl\Tenancy\Bootstrappers\DatabaseTenancyBootstrapper::class,
        'cache' => Stancl\Tenancy\Bootstrappers\CacheTenancyBootstrapper::class,
        'filesystem' => Stancl\Tenancy\Bootstrappers\FilesystemTenancyBootstrapper::class,
        'queue' => Stancl\Tenancy\Bootstrappers\QueueTenancyBootstrapper::class,
        // 'redis' => Stancl\Tenancy\Bootstrappers\RedisTenancyBootstrapper::class, // Note: phpredis is needed
    ],

    /**
     * Database tenancy config. Used by DatabaseTenancyBootstrapper.
     */
    'database' => [
        /**
         * Tenant database names are created like this:
         * prefix + tenant_id + suffix.
         */
        'prefix' => 'tenant',
        'suffix' => '',
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
         * Use this database manager for MySQL to have a DB user created for each tenant database.
         * You can customize the grants given to these users by changing the $grants property.
         */
        // 'mysql' => Stancl\Tenancy\TenantDatabaseManagers\PermissionControlledMySQLDatabaseManager::class,

        /**
         * Disable the pgsql manager above, and enable the one below if you
         * want to separate tenant DBs by schemas rather than databases.
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
        // Stancl\Tenancy\Features\UserImpersonation::class,
        // Stancl\Tenancy\Features\TenantConfig::class, // https://tenancy.samuelstancl.me/docs/v2/features/tenant-config/
        // Stancl\Tenancy\Features\CrossDomainRedirect::class, // https://tenancy.samuelstancl.me/docs/v2/features/tenant-redirect/
    ],

    'migration_parameters' => [
        '--force' => true, // Set this to true to be able to run migrations in production
        '--path' => [database_path('migrations/tenant')],
    ],

    'seeder_parameters' => [
        '--class' => 'DatabaseSeeder', // root seeder class, e.g.: 'DatabaseSeeder'
        // '--force' => true,
    ],
];
