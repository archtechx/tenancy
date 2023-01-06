<?php

declare(strict_types=1);

use Stancl\Tenancy\Middleware;
use Stancl\Tenancy\Resolvers;

return [
    /**
     * Configuration for the models used by Tenancy.
     */
    'models' => [
        'tenant' => Stancl\Tenancy\Database\Models\Tenant::class,
        'domain' => Stancl\Tenancy\Database\Models\Domain::class,

        /**
         * Name of the column used to relate models to tenants.
         *
         * This is used by the HasDomains trait, and models that use the BelongsToTenant trait (used in single-database tenancy).
         */
        'tenant_key_column' => 'tenant_id',

        /**
         * Used for generating tenant IDs.
         *
         *   - Feel free to override this with a custom class that implements the UniqueIdentifierGenerator interface.
         *   - To use autoincrement IDs, set this to null and update the `tenants` table migration to use an autoincrement column.
         *     SECURITY NOTE: Keep in mind that autoincrement IDs come with *potential* enumeration issues (such as tenant storage URLs).
         */
        'id_generator' => Stancl\Tenancy\UUIDGenerator::class,
    ],

    /**
     * The list of domains hosting your central app.
     *
     * Only relevant if you're using the domain or subdomain identification middleware.
     */
    'central_domains' => [
        '127.0.0.1',
        'localhost',
    ],

    'identification' => [
        /**
         * The default middleware used for tenant identification.
         *
         * If you use multiple forms of identification, you can set this to the "main" approach you use.
         */
        'default_middleware' => Middleware\InitializeTenancyByDomain::class,// todo@identification add this to a 'tenancy' mw group

        /**
         * All of the identification middleware used by the package.
         *
         * If you write your own, make sure to add them to this array.
         */
        'middleware' => [
            Middleware\InitializeTenancyByDomain::class,
            Middleware\InitializeTenancyBySubdomain::class,
            Middleware\InitializeTenancyByDomainOrSubdomain::class,
            Middleware\InitializeTenancyByPath::class,
            Middleware\InitializeTenancyByRequestData::class,
        ],

        /**
         * Tenant resolvers used by the package.
         *
         * Resolvers which implement the CachedTenantResolver contract have options for configuring the caching details.
         * If you add your own resolvers, do not add the 'cache' key unless your resolver is based on CachedTenantResolver.
         */
        'resolvers' => [
            Resolvers\DomainTenantResolver::class => [
                'cache' => false,
                'cache_ttl' => 3600, // seconds
                'cache_store' => null, // default
            ],
            Resolvers\PathTenantResolver::class => [
                'tenant_parameter_name' => 'tenant',

                'cache' => false,
                'cache_ttl' => 3600, // seconds
                'cache_store' => null, // default
            ],
            Resolvers\RequestDataTenantResolver::class => [
                'cache' => false,
                'cache_ttl' => 3600, // seconds
                'cache_store' => null, // default
            ],
        ],

        // todo@docs update integration guides to use Stancl\Tenancy::defaultMiddleware()
    ],

    /**
     * Tenancy bootstrappers are executed when tenancy is initialized.
     * Their responsibility is making Laravel features tenant-aware.
     *
     * To configure their behavior, see the config keys below.
     */
    'bootstrappers' => [
        Stancl\Tenancy\Bootstrappers\DatabaseTenancyBootstrapper::class,
        Stancl\Tenancy\Bootstrappers\CacheTenancyBootstrapper::class,
        Stancl\Tenancy\Bootstrappers\FilesystemTenancyBootstrapper::class,
        Stancl\Tenancy\Bootstrappers\QueueTenancyBootstrapper::class,
        Stancl\Tenancy\Bootstrappers\BatchTenancyBootstrapper::class,
        // Stancl\Tenancy\Bootstrappers\MailTenancyBootstrapper::class, // Queueing mail requires using QueueTenancyBootstrapper with $forceRefresh set to true
        // Stancl\Tenancy\Bootstrappers\RedisTenancyBootstrapper::class, // Note: phpredis is needed
    ],


    /**
     * Pending tenants config.
     * This is useful if you're looking for a way to always have a tenant ready to be used.
     */
    'pending' => [
        /**
         * If disabled, pending tenants will be excluded from all tenant queries.
         * You can still use ::withPending(), ::withoutPending() and ::onlyPending() to include or exclude the pending tenants regardless of this setting.
         * Note: when disabled, this will also ignore pending tenants when running the tenant commands (migration, seed, etc.)
         */
        'include_in_queries' => true,
        /**
         * Defines how many pending tenants you want to have ready in the pending tenant pool.
         * This depends on the volume of tenants you're creating.
         */
        'count' => env('TENANCY_PENDING_COUNT', 5),
    ],

    /**
     * Database tenancy config. Used by DatabaseTenancyBootstrapper.
     */
    'database' => [
        'central_connection' => env('DB_CONNECTION', 'central'),

        /**
         * Connection used as a "template" for the dynamically created tenant database connection.
         * Note: don't name your template connection tenant. That name is reserved by package.
         */
        'template_tenant_connection' => null,

        /**
         * The name of the temporary connection used for creating and deleting tenant databases.
         */
        'tenant_host_connection_name' => 'tenant_host_connection',

        /**
         * Tenant database names are created like this:
         * prefix + tenant_id + suffix.
         */
        'prefix' => 'tenant',
        'suffix' => '',

        /**
         * TenantDatabaseManagers are classes that handle the creation & deletion of tenant databases.
         */
        'managers' => [
            'sqlite' => Stancl\Tenancy\Database\TenantDatabaseManagers\SQLiteDatabaseManager::class,
            'mysql' => Stancl\Tenancy\Database\TenantDatabaseManagers\MySQLDatabaseManager::class,
            'pgsql' => Stancl\Tenancy\Database\TenantDatabaseManagers\PostgreSQLDatabaseManager::class,
            'sqlsrv' => Stancl\Tenancy\Database\TenantDatabaseManagers\MicrosoftSQLDatabaseManager::class,

            /**
             * Use this database manager for MySQL to have a DB user created for each tenant database.
             * You can customize the grants given to these users by changing the $grants property.
             */
            // 'mysql' => Stancl\Tenancy\Database\TenantDatabaseManagers\PermissionControlledMySQLDatabaseManager::class,

            /**
             * Disable the pgsql manager above, and enable the one below if you
             * want to separate tenant DBs by schemas rather than databases.
             */
            // 'pgsql' => Stancl\Tenancy\Database\TenantDatabaseManagers\PostgreSQLSchemaManager::class, // Separate by schema instead of database
        ],

        // todo docblock
        'drop_tenant_databases_on_migrate_fresh' => false,
    ],

    /**
     * Cache tenancy config. Used by CacheTenancyBootstrapper.
     *
     * This works for all Cache facade calls, cache() helper
     * calls and direct calls to injected cache stores.
     *
     * Each key in cache will have a tag applied on it. This tag is used to
     * scope the cache both when writing to it and when reading from it.
     *
     * You can clear cache selectively by specifying the tag.
     */
    'cache' => [
        'tag_base' => 'tenant', // This tag_base, followed by the tenant_id, will form a tag that will be applied on each cache call.
    ],

    /**
     * Filesystem tenancy config. Used by FilesystemTenancyBootstrapper.
     * https://tenancyforlaravel.com/docs/v3/tenancy-bootstrappers/#filesystem-tenancy-boostrapper.
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
         * See https://tenancyforlaravel.com/docs/v3/tenancy-bootstrappers/#filesystem-tenancy-boostrapper
         */
        'root_override' => [
            // Disks whose roots should be overriden after storage_path() is suffixed.
            'local' => '%storage_path%/app/',
            'public' => '%storage_path%/app/public/',
        ],

        /*
         * Tenant-aware Storage::disk()->url() can be enabled for specific local disks here
         * by mapping the disk's name to a name with '%tenant_id%' (this will be used as the public name of the disk).
         * Doing that will override the disk's default URL with a URL containing the current tenant's key.
         *
         * For example, Storage::disk('public')->url('') will return https://your-app.test/storage/ by default.
         * After adding 'public' => 'public-%tenant_id%' to 'url_override',
         * the returned URL will be https://your-app.test/public-1/ (%tenant_id% gets substitued by the current tenant's ID).
         *
         * Use `php artisan tenants:link` to create a symbolic link from the tenant's storage to its public directory.
         */
        'url_override' => [
            // Note that the local disk you add must exist in the tenancy.filesystem.root_override config
            // todo@v4 Rename %tenant_id% to %tenant_key%
            // todo@v4 Rename url_override to something that describes the config key better
            'public' => 'public-%tenant_id%',
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
     * Features are classes that provide additional functionality
     * not needed for tenancy to be bootstrapped. They are run
     * regardless of whether tenancy has been initialized.
     *
     * See the documentation page for each class to
     * understand which ones you want to enable.
     */
    'features' => [
        // Stancl\Tenancy\Features\UserImpersonation::class,
        // Stancl\Tenancy\Features\TelescopeTags::class,
        // Stancl\Tenancy\Features\UniversalRoutes::class,
        // Stancl\Tenancy\Features\TenantConfig::class, // https://tenancyforlaravel.com/docs/v3/features/tenant-config
        // Stancl\Tenancy\Features\CrossDomainRedirect::class, // https://tenancyforlaravel.com/docs/v3/features/cross-domain-redirect
    ],

    /**
     * Should tenancy routes be registered.
     *
     * Tenancy routes include tenant asset routes. By default, this route is
     * enabled. But it may be useful to disable them if you use external
     * storage (e.g. S3 / Dropbox) or have a custom asset controller.
     */
    'routes' => true,

    /**
     * Parameters used by the tenants:migrate command.
     */
    'migration_parameters' => [
        '--force' => true, // This needs to be true to run migrations in production.
        '--path' => [database_path('migrations/tenant')],
        '--schema-path' => database_path('schema/tenant-schema.dump'),
        '--realpath' => true,
    ],

    /**
     * Parameters used by the tenants:seed command.
     */
    'seeder_parameters' => [
        '--class' => 'Database\Seeders\DatabaseSeeder', // root seeder class
        // '--force' => true,
    ],
];
