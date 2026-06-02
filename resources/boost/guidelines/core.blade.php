<stancl-tenancy-guidelines>
=== core rules ===

# Stancl Tenancy Guidelines

These guidelines are for Laravel applications using `stancl/tenancy`. They are based on this package's source, installer, config, stubs, routes, migrations, service provider, commands, bootstrappers, middleware, models, and tests.

## Package Context

- Composer package: `stancl/tenancy`
- Purpose: automatic multi-tenancy for Laravel applications
- Service provider: `Stancl\Tenancy\TenancyServiceProvider`
- Facades: `Tenancy`, `GlobalCache`
- Core singleton: `Stancl\Tenancy\Tenancy`
- Database manager singleton: `Stancl\Tenancy\Database\DatabaseManager`
- Current tenant contract binding: `Stancl\Tenancy\Contracts\Tenant`
- Current domain contract binding: `Stancl\Tenancy\Contracts\Domain`
- Default tenant model: `Stancl\Tenancy\Database\Models\Tenant`
- Default domain model: `Stancl\Tenancy\Database\Models\Domain`
- Default impersonation token model: `Stancl\Tenancy\Database\Models\ImpersonationToken`
- Default tenant key relation column: `tenant_id`
- Reserved tenant database connection name: `tenant`
- Default central connection config: `tenancy.database.central_connection`, usually `env('DB_CONNECTION', 'central')`

## Source Files To Inspect

Before changing tenancy behavior, inspect the relevant local package files. Do not guess package behavior from memory.

- `README.md`
- `composer.json`
- `src/TenancyServiceProvider.php`
- `src/Tenancy.php`
- `src/helpers.php`
- `assets/config.php`
- `assets/routes.php`
- `assets/tenant_routes.stub.php`
- `assets/TenancyServiceProvider.stub.php`
- `assets/migrations/*`
- `assets/impersonation-migrations/*`
- `assets/resource-syncing-migrations/*`
- `src/Middleware/*`
- `src/Resolvers/*`
- `src/Bootstrappers/*`
- `src/Commands/*`
- `src/Database/Models/*`
- `src/Database/Concerns/*`
- `src/Database/TenantDatabaseManagers/*`
- `src/Features/*`
- `src/Jobs/*`
- `resources/boost/skills/tenancy/references/package.md`
- `tests/*` for expected behavior

Use Laravel documentation for framework-level behavior such as service providers, vendor publishing, routes, middleware, migrations, queues, cache, filesystem, database, and testing.

## Installation Steps

Follow every installation step. Do not skip setup files or migrations.

1. Install the package with Composer.

```bash
composer require stancl/tenancy
```

2. Run the package installer.

```bash
php artisan tenancy:install --no-interaction
```

3. Confirm the installer published the config.

```text
config/tenancy.php
```

4. Confirm the installer published tenant routes.

```text
routes/tenant.php
```

5. Confirm the installer published the application tenancy service provider.

```text
app/Providers/TenancyServiceProvider.php
```

6. Confirm the installer published central migrations.

```text
database/migrations/2019_09_15_000010_create_tenants_table.php
database/migrations/2019_09_15_000020_create_domains_table.php
```

7. Confirm the installer created the tenant migration directory.

```text
database/migrations/tenant
```

8. Review and adjust `config/tenancy.php` before running migrations. Decide identification, bootstrappers, database isolation, central domains, route mode, and tenant migration parameters first.

9. Run central migrations for the main application database.

```bash
php artisan migrate
```

10. Add tenant-specific migrations under `database/migrations/tenant`.

11. Create tenants using the configured tenant model and attach domains when using domain, subdomain, or domain-or-subdomain identification.

12. Run tenant migrations after tenants exist.

```bash
php artisan tenants:migrate
```

13. Seed tenant databases only when needed.

```bash
php artisan tenants:seed
```

14. If tenant-aware local public storage URLs are enabled, create tenant symlinks.

```bash
php artisan tenants:link
```

15. Test central routes and tenant routes separately before shipping.

## Manual Publish Commands

Prefer `tenancy:install`. Use manual publishing only when intentionally publishing a specific group.

```bash
php artisan vendor:publish --provider="Stancl\Tenancy\TenancyServiceProvider" --tag=config
php artisan vendor:publish --provider="Stancl\Tenancy\TenancyServiceProvider" --tag=routes
php artisan vendor:publish --provider="Stancl\Tenancy\TenancyServiceProvider" --tag=providers
php artisan vendor:publish --provider="Stancl\Tenancy\TenancyServiceProvider" --tag=migrations
php artisan vendor:publish --provider="Stancl\Tenancy\TenancyServiceProvider" --tag=impersonation-migrations
php artisan vendor:publish --provider="Stancl\Tenancy\TenancyServiceProvider" --tag=resource-syncing-migrations
```

## Published Files

The package publishes these files and directories:

- `assets/config.php` to `config/tenancy.php`
- `assets/tenant_routes.stub.php` to `routes/tenant.php`
- `assets/TenancyServiceProvider.stub.php` to `app/Providers/TenancyServiceProvider.php`
- `assets/migrations/2019_09_15_000010_create_tenants_table.php` to central migrations
- `assets/migrations/2019_09_15_000020_create_domains_table.php` to central migrations
- `assets/impersonation-migrations/2020_05_15_000010_create_tenant_user_impersonation_tokens_table.php` when user impersonation is used
- `assets/resource-syncing-migrations/2020_05_11_000002_create_tenant_resources_table.php` when resource syncing is used
- `database/migrations/tenant` is created by `tenancy:install` for tenant migrations

## Installer Behavior

`php artisan tenancy:install` performs these package-defined steps:

- Publishes config using tag `config`
- Publishes routes using tag `routes`
- Publishes provider using tag `providers`
- Publishes tenant and domain migrations using tag `migrations`
- Creates `database/migrations/tenant`
- Skips existing files and warns instead of overwriting them
- Shows an interactive GitHub support prompt unless `--no-interaction` is used

Use `--no-interaction` in automation and agent workflows.

## Core Config Checklist

Always review these `config/tenancy.php` sections before implementation:

- `models.tenant`
- `models.domain`
- `models.impersonation_token`
- `models.tenant_key_column`
- `models.id_generator`
- `identification.central_domains`
- `identification.default_middleware`
- `identification.middleware`
- `identification.domain_identification_middleware`
- `identification.path_identification_middleware`
- `identification.resolvers`
- `bootstrappers`
- `database.central_connection`
- `database.template_tenant_connection`
- `database.tenant_host_connection_name`
- `database.prefix`
- `database.suffix`
- `database.managers`
- `database.drop_tenant_databases_on_migrate_fresh`
- `rls.manager`
- `rls.user.username`
- `rls.user.password`
- `rls.session_variable_name`
- `cache.prefix`
- `cache.stores`
- `cache.scope_sessions`
- `cache.tag_base`
- `filesystem.suffix_base`
- `filesystem.disks`
- `filesystem.root_override`
- `filesystem.url_override`
- `filesystem.scope_cache`
- `filesystem.scope_sessions`
- `filesystem.suffix_storage_path`
- `filesystem.asset_helper_override`
- `redis.prefix`
- `redis.prefixed_connections`
- `features`
- `routes`
- `default_route_mode`
- `pending.include_in_queries`
- `pending.count`
- `migration_parameters`
- `seeder_parameters`

## Tenant Identification

Decide the identification strategy before writing routes, middleware, model logic, URLs, tests, or tenant creation flows.

Built-in identification middleware:

- `Stancl\Tenancy\Middleware\InitializeTenancyByDomain`
- `Stancl\Tenancy\Middleware\InitializeTenancyBySubdomain`
- `Stancl\Tenancy\Middleware\InitializeTenancyByDomainOrSubdomain`
- `Stancl\Tenancy\Middleware\InitializeTenancyByPath`
- `Stancl\Tenancy\Middleware\InitializeTenancyByRequestData`
- `Stancl\Tenancy\Middleware\InitializeTenancyByOriginHeader`

Related middleware:

- `Stancl\Tenancy\Middleware\PreventAccessFromUnwantedDomains`
- `Stancl\Tenancy\Middleware\CheckTenantForMaintenanceMode`
- `Stancl\Tenancy\Middleware\ScopeSessions`

Rules:

- Configure `identification.central_domains` correctly for domain and subdomain identification.
- Use `PreventAccessFromUnwantedDomains` only with domain-oriented identification middleware listed in `identification.domain_identification_middleware`.
- For path identification, check `PathTenantResolver::tenantParameterName()` and `identification.resolvers[PathTenantResolver::class]` before changing route parameters.
- For request data identification, configure header, cookie, and query parameter names in `RequestDataTenantResolver` config.
- For origin header identification, verify trusted frontend/origin behavior and failure handling.
- Failed identification throws package exceptions unless an `onFail` callback is registered.
- If custom middleware is added, also add it to the matching config array when the package needs to recognize its category.

## Tenant Resolvers

Built-in resolvers:

- `Stancl\Tenancy\Resolvers\DomainTenantResolver`
- `Stancl\Tenancy\Resolvers\PathTenantResolver`
- `Stancl\Tenancy\Resolvers\RequestDataTenantResolver`

Resolver guidance:

- Enable resolver cache only deliberately and invalidate it when domain or tenant lookup data changes.
- Use `cache_ttl` and `cache_store` when resolver caching is enabled.
- For path resolver custom binding fields, configure `allowed_extra_model_columns`.
- For request data resolver, set unused identification channels to `null`.
- Use `tenant_model_column` when lookup should use a custom tenant column instead of the tenant key.

## Route Setup

The published `routes/tenant.php` stub uses:

```php
Route::middleware([
    'web',
    Middleware\InitializeTenancyByDomain::class,
    Middleware\PreventAccessFromUnwantedDomains::class,
    Middleware\ScopeSessions::class,
])->group(function () {
    // Tenant routes...
});
```

The published application `TenancyServiceProvider` loads tenant routes like this:

- Waits until the app is booted
- Checks `base_path('routes/tenant.php')`
- Applies middleware group `tenant`
- Uses `static::$controllerNamespace`
- Can clone routes as tenant routes through `CloneRoutesAsTenant`

The package service provider registers empty route middleware groups:

- `clone`
- `universal`
- `tenant`
- `central`

Route mode rules:

- `tenancy.default_route_mode` defaults to `RouteMode::CENTRAL`.
- Override default route mode by applying `central`, `tenant`, or `universal` middleware.
- Keep central and tenant routes explicit.
- Use `routes/tenant.php` for tenant application routes when the published stub is used.
- Use `universal` only for routes that intentionally work in both contexts.
- Do not use ad hoc request host checks when package middleware/route modes cover the behavior.

## Tenant Asset Routes

When `tenancy.routes` is true, the package loads `assets/routes.php` and registers:

- `/tenancy/assets/{path?}` named `stancl.tenancy.asset`
- `/{tenant}/tenancy/assets/{path?}` named `tenant.stancl.tenancy.asset` for path identification, behind `tenant` middleware

Guidance:

- Disable `tenancy.routes` only if using external storage or a custom asset controller.
- If `filesystem.url_override` is used for local disks, run `php artisan tenants:link`.
- For global assets, use global asset helpers when `filesystem.asset_helper_override` is enabled.
- Prefer explicit `tenant_asset()` calls for tenant-specific assets when global packages call `asset()` internally.

## Tenant Context API

Use package APIs for context switching. Do not manually mutate Laravel globals.

```php
tenancy()->initialize($tenant);

tenancy()->end();

tenancy()->reinitialize();

$tenant->run(function () {
    // Code runs in tenant context.
});

tenancy()->central(function () {
    // Code runs in central context and then safely reverts.
});
```

Important runtime behavior:

- `initialize()` accepts a tenant model, tenant ID, or tenant key string.
- `initialize()` ends the previous tenant context before switching to a different tenant.
- `run()` is atomic and reverts to the previous tenant or central context in `finally`.
- `central()` is atomic and restores the previous tenant context when finished.
- `reinitialize()` is useful when tenant attributes used by bootstrappers changed during a request.
- `bootstrapFeatures()` is idempotent for features already bootstrapped, but feature bootstrapping is irreversible.
- `tenancy()->find($id, $column = null, $withRelations = false)` uses the configured tenant model.
- `Tenancy::tenantKeyColumn()` reads `tenancy.models.tenant_key_column` and defaults to `tenant_id`.

## Helpers And Facades

Use package helpers and facades where appropriate:

- `tenancy()` for the `Tenancy` singleton
- `tenant()` for current tenant access and tenant attribute lookup
- `central()` for central-context execution
- `globalCache()` or `GlobalCache` for cache that should remain central
- `global_asset()` when asset helper tenancy is enabled and the asset should remain global
- `tenant_asset()` for tenant-specific local assets
- `Tenancy` facade for the tenancy manager
- `GlobalCache` facade for central cache access

## Bootstrappers

Default bootstrappers in `assets/config.php`:

- `DatabaseTenancyBootstrapper`
- `CacheTenancyBootstrapper`
- `FilesystemTenancyBootstrapper`
- `QueueTenancyBootstrapper`
- `DatabaseSessionBootstrapper`

Optional bootstrappers:

- `CacheTagsBootstrapper`
- `DatabaseCacheBootstrapper`
- `RedisTenancyBootstrapper`
- `TenantConfigBootstrapper`
- `RootUrlBootstrapper`
- `UrlGeneratorBootstrapper`
- `MailConfigBootstrapper`
- `BroadcastingConfigBootstrapper`
- `BroadcastChannelPrefixBootstrapper`
- `Bootstrappers\Integrations\FortifyRouteBootstrapper`
- `Bootstrappers\Integrations\ScoutPrefixBootstrapper`
- `PostgresRLSBootstrapper`
- `PersistentQueueTenancyBootstrapper`

Bootstrapper rules:

- Configure bootstrappers before writing application workarounds.
- Do not manually change DB connections, cache prefixes, filesystem roots, queue payloads, Redis prefixes, URL roots, or mail/broadcasting config when a bootstrapper owns it.
- `DatabaseCacheBootstrapper` must run after `DatabaseTenancyBootstrapper`.
- `RedisTenancyBootstrapper` needs phpredis and is for direct Redis calls, not normal cache-only Redis usage.
- `TenantConfigBootstrapper` should be preferred over the deprecated `TenantConfig` feature.
- `RootUrlBootstrapper` affects CLI URL generation in tenant context.
- `UrlGeneratorBootstrapper` is important for path/query-string route generation.
- If tenant state appears partially applied, inspect `tenancy()->getBootstrappers()` and `tenancy.bootstrappers`.

## Database Tenancy

Database config supports:

- separate tenant databases
- PostgreSQL schema isolation
- permission-controlled tenant database users
- SQLite tenant database management
- MySQL/MariaDB tenant database management
- PostgreSQL tenant database management
- SQL Server tenant database management
- PostgreSQL RLS for single-database tenancy

Rules:

- `tenant` is a reserved dynamic connection name; do not use it as the template tenant connection name.
- Use `database.template_tenant_connection` for the tenant connection template.
- Use `database.tenant_host_connection_name` for temporary creation/deletion connection behavior.
- Tenant database names are generated as `prefix + tenant_id + suffix`.
- Use permission-controlled managers only when tenant-specific DB users are required.
- For PostgreSQL schemas, swap the pgsql manager to a schema manager instead of a database manager.
- `database.drop_tenant_databases_on_migrate_fresh` controls package behavior for `migrate:fresh` through the package override.

## Central Migrations

The base central migrations create:

- `tenants` table with string primary `id`, timestamps, and nullable JSON `data`
- `domains` table with integer `id`, unique `domain`, tenant key column, timestamps, and foreign key to `tenants.id`

Guidance:

- Add custom tenant columns to the `tenants` migration before running it.
- If using auto-increment tenant IDs, set `models.id_generator` to `null` and update the tenants migration primary key accordingly.
- Keep domain values unique and lowercase behavior in mind; the default domain model converts domains to lowercase.
- The domains migration uses `Tenancy::tenantKeyColumn()` for the tenant foreign key.

## Tenant Migrations And Seeders

Tenant migrations are configured by `tenancy.migration_parameters`:

- `--force` defaults to true
- `--path` defaults to `database/migrations/tenant`
- `--schema-path` defaults to `database/schema/tenant-schema.dump`
- `--realpath` defaults to true

Tenant seeders are configured by `tenancy.seeder_parameters`:

- `--class` defaults to `Database\Seeders\DatabaseSeeder`

Rules:

- Put tenant database migrations in `database/migrations/tenant` by default.
- Use `php artisan tenants:migrate` for tenant migrations.
- Use `php artisan tenants:rollback` for tenant rollback.
- Use `php artisan tenants:migrate-fresh` for tenant migrate fresh behavior.
- Use `php artisan tenants:seed` for tenant seeders.
- Do not run normal Laravel migrations expecting them to apply to tenant databases.
- Review `migration_parameters` and `seeder_parameters` before changing command calls.
- Use `--tenants=*` options when only specific tenants should be affected.
- Use `--skip-failing` deliberately when tenant migration failures should not stop the whole command.
- Use `--processes` only after confirming database and application code are safe for concurrent tenant operations.

## Tenant Models

Default tenant model traits include:

- `VirtualColumn`
- `CentralConnection`
- `GeneratesIds`
- `HasInternalKeys`
- `TenantRun`
- `InitializationHelpers`
- `InvalidatesResolverCache`

Default tenant model behavior:

- table: `tenants`
- primary key: `id`
- guarded: empty array
- dispatches creating, created, saving, saved, updating, updated, deleting, deleted tenant events
- `Tenant::current()` returns current tenant
- `Tenant::currentOrFail()` throws if tenancy is not initialized
- tenant collection class: `Stancl\Tenancy\Database\TenantCollection`

Guidance:

- Use the configured tenant model from `config('tenancy.models.tenant')`.
- If replacing the tenant model, implement `Stancl\Tenancy\Contracts\Tenant`.
- Preserve package traits unless there is a specific tested reason to replace them.
- Use tenant model events and the published application service provider's pipelines for provisioning.

## Domain Models

Default domain model traits include:

- `CentralConnection`
- `EnsuresDomainIsNotOccupied`
- `ConvertsDomainsToLowercase`
- `InvalidatesTenantsResolverCache`

Default domain model behavior:

- guarded: empty array
- belongs to the configured tenant model using `Tenancy::tenantKeyColumn()`
- dispatches creating, created, saving, saved, updating, updated, deleting, deleted domain events

Guidance:

- Use domains for domain/subdomain/domain-or-subdomain identification.
- Do not bypass domain uniqueness checks.
- Ensure domain cache invalidates when domain or tenant lookup data changes.

## Single-Database Tenancy

For single-database tenancy, use package traits and scopes instead of hand-written tenant filters.

Relevant concerns:

- `BelongsToTenant`
- `FillsCurrentTenant`
- `TenantConnection`
- `CentralConnection`
- `TenantScope`
- `HasScopedValidationRules`
- `RLSModel` when PostgreSQL RLS is used

Rules:

- Apply tenant scoping consistently to tenant-owned models.
- Make tenant-owned models fill the current tenant key automatically where appropriate.
- Test central resources and tenant resources separately.
- For PostgreSQL RLS, configure `rls.user`, `rls.manager`, and `rls.session_variable_name`, then use package RLS commands/policies.

## Resource Syncing

Resource syncing assets include:

- migration: `tenant_resources`
- events in `Stancl\Tenancy\ResourceSyncing\Events`
- listeners in `Stancl\Tenancy\ResourceSyncing\Listeners`
- traits/classes such as `ResourceSyncing`, `SyncMaster`, `Syncable`, `TenantPivot`, `TenantMorphPivot`

Guidance:

- Publish `resource-syncing-migrations` before using resource syncing.
- Keep central resource and tenant resource lifecycles explicit.
- Use package events/listeners from the published provider instead of custom sync loops.
- If soft-deleted synced resources are needed, configure the listener query scope in the application `TenancyServiceProvider` as shown in the stub.

## User Impersonation

User impersonation uses:

- feature: `Stancl\Tenancy\Features\UserImpersonation`
- model: `Stancl\Tenancy\Database\Models\ImpersonationToken`
- migration: `tenant_user_impersonation_tokens`
- command: `tenants:purge-impersonation-tokens`

Guidance:

- Publish `impersonation-migrations` before enabling impersonation.
- Enable the `UserImpersonation` feature in `tenancy.features`.
- Run central migrations after publishing the impersonation migration.
- Purge expired tokens with `php artisan tenants:purge-impersonation-tokens`.
- Verify guard, redirect URL, remember flag, tenant match, and token TTL in tests.

## Pending Tenants

Pending tenant config:

- `pending.include_in_queries`
- `pending.count`, defaulting to `TENANCY_PENDING_COUNT` or 5

Commands:

- `php artisan tenants:pending-create`
- `php artisan tenants:pending-create --count=10`
- `php artisan tenants:pending-clear`
- `php artisan tenants:pending-clear --older-than-days=7`
- `php artisan tenants:pending-clear --older-than-hours=12`

Rules:

- If `pending.include_in_queries` is false, pending tenants are excluded from tenant queries and tenant commands.
- Use `withPending()`, `withoutPending()`, and `onlyPending()` intentionally when querying pending tenants.
- Do not assume pending tenants are included in migrations or seeds when config excludes them.

## Tenant Lifecycle And Jobs

The published application `TenancyServiceProvider` wires lifecycle events to job pipelines.

Default `TenantCreated` pipeline:

- `CreateDatabase`
- `MigrateDatabase`
- optional `SeedDatabase`
- optional `CreateStorageSymlinks`
- custom provisioning jobs

Default deleting/deleted tenant pipelines:

- `DeleteDomains` during `DeletingTenant`
- optional `DeleteTenantStorage`
- optional `RemoveStorageSymlinks`
- `DeleteDatabase` during `TenantDeleted`
- optional resource-syncing cleanup

Rules:

- Add tenant provisioning logic to the event pipeline rather than scattering it through controllers.
- Decide whether pipelines should be queued using `shouldBeQueued()`.
- Keep database creation, migration, seeding, storage, and domain deletion order explicit.
- Test tenant creation and deletion side effects.

## Filesystem, Storage, And Assets

Filesystem config controls:

- tenant storage suffix base
- scoped disks
- local root overrides
- URL overrides
- file cache scoping
- file session scoping
- `storage_path()` suffixing
- `asset()` helper override

Rules:

- Keep `suffix_storage_path` enabled for local disk tenancy unless using external storage like S3 and the app is tested without it.
- Add local disks to both `filesystem.disks` and `filesystem.root_override` when root override is needed.
- Use `tenants:link` when `filesystem.url_override` maps local public disks.
- Use `tenants:link --remove` when removing tenant symlinks.
- Use `tenants:link --relative` only when relative symlinks are required by deployment.
- Use `tenants:link --force` when recreating existing symlinks deliberately.
- Be careful with `asset_helper_override`; packages that call `asset()` may unexpectedly become tenant-aware.

## Cache, Global Cache, Sessions, And Redis

Cache rules:

- `CacheTenancyBootstrapper` scopes cache by changing `cache.prefix`.
- `CacheTagsBootstrapper` scopes using tags and is an alternative pattern.
- `DatabaseCacheBootstrapper` scopes database cache by tenant DB connection and must run after database tenancy.
- Use `GlobalCache`/`globalCache()` for cache that must remain central.
- If session driver is cache-based, `cache.scope_sessions` may add the session store to prefixed stores.

Filesystem session rules:

- `filesystem.scope_sessions` scopes file sessions under tenant storage.
- Use `ScopeSessions` middleware on tenant routes when session scoping is required.

Redis rules:

- `RedisTenancyBootstrapper` is for direct Redis facade/injected Redis usage.
- phpredis is required for Redis tenancy.
- Redis cache alone usually does not need `RedisTenancyBootstrapper`; cache scoping covers cache usage.

## Queue Behavior

Queue bootstrappers:

- `QueueTenancyBootstrapper`
- `PersistentQueueTenancyBootstrapper`

Rules:

- Use the queue bootstrapper for tenant-aware queued jobs.
- Do not manually inject tenant IDs into every job if the package bootstrapper already handles payload/context.
- Test queued jobs from central context and tenant context.
- Use the persistent queue bootstrapper only when worker processes intentionally stay tenant-aware across jobs.

## URL, Routes, Mail, Broadcasting, Fortify, Scout

Use optional bootstrappers for integration-specific runtime config:

- `RootUrlBootstrapper` for tenant root URL in CLI/context URL generation
- `UrlGeneratorBootstrapper` for tenant-aware route generation and tenant parameters
- `MailConfigBootstrapper` for tenant mail configuration
- `BroadcastingConfigBootstrapper` for tenant broadcaster credentials
- `BroadcastChannelPrefixBootstrapper` for tenant-prefixed broadcast channel names
- `FortifyRouteBootstrapper` for tenant Fortify route/redirect behavior
- `ScoutPrefixBootstrapper` for tenant-specific Scout prefixes

Rules:

- Configure these bootstrappers instead of writing custom service-provider mutations.
- Use the `overrideUrlInTenantContext()` hook in the published application provider for CLI root URL customization.
- For Livewire v3, follow the provider stub pattern to make the Livewire update route universal when needed.

## Optional Features

Features are bootstrapped independently from tenant initialization and are enabled via `tenancy.features`.

Available features:

- `Stancl\Tenancy\Features\UserImpersonation`
- `Stancl\Tenancy\Features\TelescopeTags`
- `Stancl\Tenancy\Features\CrossDomainRedirect`
- `Stancl\Tenancy\Features\ViteBundler`
- `Stancl\Tenancy\Features\DisallowSqliteAttach`
- `Stancl\Tenancy\Features\TenantConfig`

Rules:

- Inspect the feature class before assuming it affects tenant initialization.
- Prefer `TenantConfigBootstrapper` over deprecated `TenantConfig` feature for mapping tenant attributes into config.
- `DisallowSqliteAttach` protects SQLite use by blocking `ATTACH`; verify PHP/version-specific behavior in tests.
- `CrossDomainRedirect` adds redirect domain behavior.
- `TelescopeTags` adds tenant tags when tenancy is initialized.
- `ViteBundler` affects tenant-aware bundling behavior.

## Artisan Commands

Use package commands instead of ad hoc loops.

Installation:

```bash
php artisan tenancy:install --no-interaction
```

Tenant migrations and seeds:

```bash
php artisan tenants:migrate
php artisan tenants:migrate --tenants=tenant-id
php artisan tenants:migrate --skip-failing
php artisan tenants:rollback
php artisan tenants:migrate-fresh
php artisan tenants:seed
```

Run commands in tenant context:

```bash
php artisan tenants:run cache:clear
php artisan tenants:run "your:command" --tenants=tenant-id
php artisan tenant:tinker
```

Tenant maintenance:

```bash
php artisan tenants:down
php artisan tenants:down --redirect=/maintenance --retry=60 --refresh=60 --secret=secret --status=503
php artisan tenants:up
```

Tenant storage symlinks:

```bash
php artisan tenants:link
php artisan tenants:link --tenants=tenant-id
php artisan tenants:link --relative
php artisan tenants:link --force
php artisan tenants:link --remove
```

Pending tenants:

```bash
php artisan tenants:pending-create
php artisan tenants:pending-create --count=10
php artisan tenants:pending-clear
php artisan tenants:pending-clear --older-than-days=7
php artisan tenants:pending-clear --older-than-hours=12
```

Other package commands:

```bash
php artisan tenants:list
php artisan tenant:dump
php artisan tenants:purge-impersonation-tokens
php artisan tenants:rls
php artisan tenants:rls --force
```

Command rules:

- Use `php artisan list` or `php artisan help <command>` to confirm available options in the installed app.
- Use `--tenants=*` when affecting only selected tenants.
- Use tenant commands for tenant databases, not central Laravel migration commands.
- Use `tenants:run` for existing Artisan commands that need tenant context.

## Maintenance Mode

Maintenance commands:

- `tenants:down`
- `tenants:up`

Related middleware:

- `CheckTenantForMaintenanceMode`

Rules:

- Use tenant maintenance mode when only tenant apps should be unavailable.
- Test bypass secret, redirect, retry, refresh, and status code behavior when configured.
- Do not confuse tenant maintenance with Laravel global maintenance mode.

## PostgreSQL RLS

RLS config and classes:

- `rls.manager`
- `rls.user.username`
- `rls.user.password`
- `rls.session_variable_name`
- `PostgresRLSBootstrapper`
- `RLSModel`
- `TableRLSManager`
- `TraitRLSManager`
- `tenants:rls`

Rules:

- Use PostgreSQL and single-database tenancy for RLS.
- Set a namespaced session variable name such as `my.current_tenant`.
- Configure one tenant database user used for all tenants, not one user per tenant.
- Run `php artisan tenants:rls` to create policies/user.
- Use `--force` only when policies should be recreated even if they already exist.
- Test policy coverage on every tenant-owned table.

## Testing Guidelines

Every tenancy behavior change must be tested programmatically.

Test at minimum:

- installation artifacts exist when testing installer behavior
- central routes remain central
- tenant routes initialize tenancy
- wrong central/tenant access is rejected
- domain, subdomain, path, request-data, or origin-header identification resolves the expected tenant
- identification failure throws or handles the expected package exception
- current tenant is available through `tenant()`, facade, and contract binding when expected
- database connection switches and reverts
- cache keys are scoped or global as intended
- filesystem roots, URLs, and storage paths are scoped as intended
- queues reinitialize tenant context around jobs
- sessions are scoped when configured
- URL generation uses tenant route names and parameters correctly
- tenant migrations, rollbacks, and seeds affect the expected tenants
- tenant creation pipelines create DBs, migrate DBs, seed DBs, create storage, and attach domains as configured
- tenant deletion pipelines delete domains, database, storage, symlinks, and resource mappings as configured
- optional features behave only when enabled
- central context is restored after `run()` and `central()` callbacks

Use existing package tests as examples:

- `tests/AutomaticModeTest.php`
- `tests/ManualModeTest.php`
- `tests/RouteMiddlewareTest.php`
- `tests/PathIdentificationTest.php`
- `tests/RequestDataIdentificationTest.php`
- `tests/OriginHeaderIdentificationTest.php`
- `tests/TenantAssetTest.php`
- `tests/CommandsTest.php`
- `tests/QueueTest.php`
- `tests/SingleDatabaseTenancyTest.php`
- `tests/RLS/*`
- `tests/ResourceSyncingTest.php`
- `tests/TenantUserImpersonationTest.php`

## Implementation Rules

- Decide identification first.
- Decide database isolation before creating application models.
- Decide bootstrappers before writing workaround code.
- Keep central and tenant routes explicit.
- Use package commands for tenant-aware operations.
- Use package context APIs instead of manual context mutation.
- Use package models, contracts, traits, events, and jobs before adding custom abstractions.
- Keep provisioning in the application `TenancyServiceProvider` event pipelines.
- Review config before changing application code.
- Test both central and tenant behavior for every change.

## Common Pitfalls

- Running normal `php artisan migrate` and expecting tenant DBs to migrate
- Forgetting to run `php artisan tenants:migrate` after tenants exist
- Skipping `routes/tenant.php` or the application `TenancyServiceProvider`
- Using the reserved connection name `tenant` as a template connection
- Mixing central and tenant routes without route modes or middleware
- Using `PreventAccessFromUnwantedDomains` with unsupported non-domain identification
- Forgetting `central_domains` for domain/subdomain apps
- Manually changing DB/cache/filesystem/queue/session/url state instead of using bootstrappers
- Enabling `asset_helper_override` without checking third-party packages that call `asset()`
- Enabling resolver caching without invalidation coverage
- Forgetting to publish impersonation or resource-syncing migrations before enabling those features
- Assuming pending tenants are included when `pending.include_in_queries` excludes them
- Using auto-increment tenant IDs without considering enumeration risk
- Adding tenant-owned models in single-database tenancy without tenant scoping
- Not verifying central context after tenant context work

</stancl-tenancy-guidelines>
