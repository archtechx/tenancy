<?php

declare(strict_types=1);

use Stancl\Tenancy\Middleware;
use Stancl\Tenancy\Resolvers;
use Stancl\Tenancy\Bootstrappers;
use Stancl\Tenancy\Enums\RouteMode;
use Stancl\Tenancy\UniqueIdentifierGenerators;

return [
    /**
     * Configuration for the models used by Tenancy.
     */
    'models' => [
        'tenant' => Stancl\Tenancy\Database\Models\Tenant::class,
        'domain' => Stancl\Tenancy\Database\Models\Domain::class,
        'impersonation_token' => Stancl\Tenancy\Database\Models\ImpersonationToken::class,

        /**
         * Name of the column used to relate models to tenants.
         *
         * This is used by the HasDomains trait, and models that use the BelongsToTenant trait (used in single-database tenancy).
         */
        'tenant_key_column' => 'tenant_id',

        /**
         * Used for generating tenant IDs.
         *
         * - Feel free to override this with a custom class that implements the UniqueIdentifierGenerator interface.
         * - To use autoincrement IDs, set this to null and update the `tenants` table migration to use an autoincrement column.
         *
         * SECURITY NOTE: Keep in mind that autoincrement IDs come with potential enumeration issues (such as tenant storage URLs).
         *
         * @see \Stancl\Tenancy\UniqueIdentifierGenerators\UUIDGenerator
         * @see \Stancl\Tenancy\UniqueIdentifierGenerators\RandomHexGenerator
         * @see \Stancl\Tenancy\UniqueIdentifierGenerators\RandomIntGenerator
         * @see \Stancl\Tenancy\UniqueIdentifierGenerators\RandomStringGenerator
         */
        'id_generator' => UniqueIdentifierGenerators\UUIDGenerator::class,
    ],

    'identification' => [
        /**
         * The list of domains hosting your central app.
         *
         * Only relevant if you're using the domain or subdomain identification middleware.
         */
        'central_domains' => [
            str(env('APP_URL'))->after('://')->before('/')->toString(),
        ],

        /**
         * The default middleware used for tenant identification.
         *
         * If you use multiple forms of identification, you can set this to the "main" approach you use.
         */
        'default_middleware' => Middleware\InitializeTenancyByDomain::class,

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
            Middleware\InitializeTenancyByOriginHeader::class,
        ],

        /**
         * Identification middleware tenancy recognizes as domain identification middleware.
         *
         * This is used for determining whether to skip the access prevention middleware.
         * PreventAccessFromUnwantedDomains is intended to be used only with the middleware included here.
         * It will get skipped if it's used with other identification middleware.
         *
         * If you're using a custom domain identification middleware, add it here.
         *
         * @see \Stancl\Tenancy\Concerns\UsableWithEarlyIdentification
         * @see \Stancl\Tenancy\Middleware\PreventAccessFromUnwantedDomains
         */
        'domain_identification_middleware' => [
            Middleware\InitializeTenancyByDomain::class,
            Middleware\InitializeTenancyBySubdomain::class,
            Middleware\InitializeTenancyByDomainOrSubdomain::class,
        ],

        /**
         * Identification middleware tenancy recognizes as path identification middleware.
         *
         * This is used for determining if a path identification middleware is used
         * during operations specific to path identification, e.g. forgetting the tenant parameter in ForgetTenantParameter.
         *
         * If you're using a custom path identification middleware, add it here.
         *
         * @see \Stancl\Tenancy\Actions\CloneRoutesAsTenant
         * @see \Stancl\Tenancy\Listeners\ForgetTenantParameter
         */
        'path_identification_middleware' => [
            Middleware\InitializeTenancyByPath::class,
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
                'cache_store' => null, // null = default
            ],
            Resolvers\PathTenantResolver::class => [
                'tenant_parameter_name' => 'tenant',
                'tenant_model_column' => null, // null = tenant key
                'tenant_route_name_prefix' => null, // null = 'tenant.'
                'allowed_extra_model_columns' => [], // used with binding route fields

                'cache' => false,
                'cache_ttl' => 3600, // seconds
                'cache_store' => null, // null = default
            ],
            Resolvers\RequestDataTenantResolver::class => [
                'cache' => false,
                'cache_ttl' => 3600, // seconds
                'cache_store' => null, // null = default
            ],
        ],
    ],

    /**
     * Tenancy bootstrappers are executed when tenancy is initialized.
     * Their responsibility is making Laravel features tenant-aware.
     *
     * To configure their behavior, see the config keys below.
     */
    'bootstrappers' => [
        // Basic Laravel features
        Bootstrappers\DatabaseTenancyBootstrapper::class,
        Bootstrappers\CacheTenancyBootstrapper::class,
        // Bootstrappers\CacheTagsBootstrapper::class, // Alternative to CacheTenancyBootstrapper
        Bootstrappers\FilesystemTenancyBootstrapper::class,
        Bootstrappers\QueueTenancyBootstrapper::class,
        // Bootstrappers\RedisTenancyBootstrapper::class, // Note: phpredis is needed

        // Adds support for the database session driver
        Bootstrappers\DatabaseSessionBootstrapper::class,

        // Configurable bootstrappers
        // Bootstrappers\RootUrlBootstrapper::class,
        // Bootstrappers\UrlGeneratorBootstrapper::class,
        // Bootstrappers\MailConfigBootstrapper::class, // Note: Queueing mail requires using QueueTenancyBootstrapper with $forceRefresh set to true
        // Bootstrappers\BroadcastingConfigBootstrapper::class,
        // Bootstrappers\BroadcastChannelPrefixBootstrapper::class,

        // Integration bootstrappers
        // Bootstrappers\Integrations\FortifyRouteBootstrapper::class,
        // Bootstrappers\Integrations\ScoutPrefixBootstrapper::class,

        // Bootstrappers\PostgresRLSBootstrapper::class,
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
             * Use these database managers to have a DB user created for each tenant database.
             * You can customize the grants given to these users by changing the $grants property.
             */
            // 'mysql' => Stancl\Tenancy\Database\TenantDatabaseManagers\PermissionControlledMySQLDatabaseManager::class,
            // 'pgsql' => Stancl\Tenancy\Database\TenantDatabaseManagers\PermissionControlledPostgreSQLDatabaseManager::class,
            // 'sqlsrv' => Stancl\Tenancy\TenantDatabaseManagers\PermissionControlledMicrosoftSQLServerDatabaseManager::class,

            /**
             * Disable the pgsql manager above, and enable the one below if you
             * want to separate tenant DBs by schemas rather than databases.
             */
            // 'pgsql' => Stancl\Tenancy\Database\TenantDatabaseManagers\PostgreSQLSchemaManager::class, // Separate by schema instead of database
            // 'pgsql' => Stancl\Tenancy\Database\TenantDatabaseManagers\PermissionControlledPostgreSQLSchemaManager::class, // Also permission controlled
        ],

        /*
         * Drop tenant databases when `php artisan migrate:fresh` is used.
         * You may want to use this locally since deleting tenants only
         * deletes their databases when they're deleted individually, not
         * when the records are mass deleted from the database.
         *
         * Note: This overrides the default MigrateFresh command.
         */
        'drop_tenant_databases_on_migrate_fresh' => false,
    ],

    /**
     * Requires PostgreSQL with single-database tenancy.
     */
    'rls' => [
        /**
         * The RLS manager responsible for generating queries for creating policies.
         *
         * @see Stancl\Tenancy\RLS\PolicyManagers\TableRLSManager
         * @see Stancl\Tenancy\RLS\PolicyManagers\TraitRLSManager
         */
        'manager' => Stancl\Tenancy\RLS\PolicyManagers\TableRLSManager::class,

        /**
         * Credentials for the tenant database user (one user for *all* tenants, not for each tenant).
         */
        'user' => [
            'username' => env('TENANCY_RLS_USERNAME'),
            'password' => env('TENANCY_RLS_PASSWORD'),
        ],

        /**
         * Postgres session variable used to store the current tenant key.
         *
         * The variable name has to include a namespace – for example, 'my.'.
         * The namespace is required because the global one is reserved for the server configuration
         */
        'session_variable_name' => 'my.current_tenant',
    ],

    /**
     * Cache tenancy config. Used by the CacheTenancyBootstrapper, the CacheTagsBootstrapper, and the custom CacheManager.
     *
     * This works for all Cache facade calls, cache() helper
     * calls and direct calls to injected cache stores.
     *
     * CacheTenancyBootstrapper:
     *   A prefix is applied *GLOBALLY*, using the `cache.prefix` config. This separates
     *   one tenant's cache from another's. The list of stores is used for refreshing
     *   them so that they re-load the prefix from the `cache.prefix` configuration.
     *
     * CacheTagsBootstrapper:
     *   Each key in cache will have a tag applied on it. This tag is used to
     *   scope the cache both when writing to it and when reading from it.
     *
     * You can clear cache selectively by specifying the tag.
     */
    'cache' => [
        'prefix' => 'tenant_%tenant%_', // This format, with the %tenant% replaced by the tenant key, and prepended by the original store prefix, will form a cache prefix that will be used for every cache key.
        'stores' => [
            env('CACHE_STORE'),
        ],

        /*
         * Should sessions be tenant-aware (only used when your session driver is cache-based).
         *
         * Note: This will implicitly add your configured session store to the list of prefixed stores above.
         */
        'scope_sessions' => true,

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
            // Disks whose roots should be overridden after storage_path() is suffixed.
            'local' => '%storage_path%/app/',
            'public' => '%storage_path%/app/public/',
        ],

        /*
         * Tenant-aware Storage::disk()->url() can be enabled for specific local disks here
         * by mapping the disk's name to a name with '%tenant%' (this will be used as the public name of the disk).
         * Doing that will override the disk's default URL with a URL containing the current tenant's key.
         *
         * For example, Storage::disk('public')->url('') will return https://your-app.test/storage/ by default.
         * After adding 'public' => 'public-%tenant%' to 'url_override',
         * the returned URL will be https://your-app.test/public-1/ (%tenant% gets substitued by the current tenant's key).
         *
         * Use `php artisan tenants:link` to create a symbolic link from the tenant's storage to its public directory.
         */
        'url_override' => [
            // Note that the local disk you add must exist in the tenancy.filesystem.root_override config
            'public' => 'public-%tenant%',
        ],

        /*
         * Should the `file` cache driver be tenant-aware.
         *
         * When this is enabled, cache files will be stored in storage/{tenant}/framework/cache.
         */
        'scope_cache' => true,

        /*
         * Should the `file` session driver be tenant-aware.
         *
         * When this is enabled, session files will be stored in storage/{tenant}/framework/sessions.
         */
        'scope_sessions' => true,

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
         * Setting this to true makes asset() calls multi-tenant. You can use global_asset() and mix()
         * for global, non-tenant-specific assets. However, you might have some issues when using
         * packages that use asset() calls inside the tenant app. To avoid such issues, you can
         * leave asset() helper tenancy disabled and explicitly use tenant_asset() calls in places
         * where you want to use tenant-specific assets (product images, avatars, etc).
         */
        'asset_helper_override' => false,
    ],

    /**
     * Redis tenancy config. Used by RedisTenancyBootstrapper.
     *
     * Note: You need phpredis to use Redis tenancy.
     *
     * Note: You don't need to use this if you're using Redis only for cache.
     * Redis tenancy is only relevant if you're making direct Redis calls,
     * either using the Redis facade or by injecting it as a dependency.
     */
    'redis' => [
        'prefix' => 'tenant_%tenant%_', // Each key in Redis will be prepended by this prefix format, with %tenant% replaced by the tenant key.
        'prefixed_connections' => [ // Redis connections whose keys are prefixed, to separate one tenant's keys from another.
            'default',
            // 'cache', // Enable this if you want to scope cache using RedisTenancyBootstrapper
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
        // Stancl\Tenancy\Features\TenantConfig::class,
        // Stancl\Tenancy\Features\CrossDomainRedirect::class,
        // Stancl\Tenancy\Features\ViteBundler::class,
        // Stancl\Tenancy\Features\DisallowSqliteAttach::class,
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
     * Make all routes central, tenant, or universal by default.
     *
     * To override the default route mode, apply the middleware of another route mode ('central', 'tenant', 'universal') to the route.
     */
    'default_route_mode' => RouteMode::CENTRAL,

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
