# Tenancy Package Reference

This reference is for package-specific details that do not need to live in `SKILL.md`.

## Main Entry Points

- `src/TenancyServiceProvider.php`
- `src/Tenancy.php`
- `assets/config.php`
- `assets/routes.php`
- `src/helpers.php`

## Published Files

The package publishes:

- `config/tenancy.php`
- `routes/tenant.php`
- `app/Providers/TenancyServiceProvider.php`
- `database/migrations/2019_09_15_000010_create_tenants_table.php`
- `database/migrations/2019_09_15_000020_create_domains_table.php`
- impersonation migrations
- resource syncing migrations

`tenancy:install` also creates `database/migrations/tenant`.

## Service Provider Behavior

`TenancyServiceProvider`:

- merges `assets/config.php` into `tenancy`
- binds `Stancl\Tenancy\Database\DatabaseManager` as a singleton
- binds `Stancl\Tenancy\Tenancy` as a singleton
- binds the current tenant to the `Stancl\Tenancy\Contracts\Tenant` contract
- binds the current domain to the `Stancl\Tenancy\Contracts\Domain` contract
- registers configured bootstrappers as singletons
- binds the configured unique ID generator to `UniqueIdentifierGenerator`
- registers package commands
- publishes config, routes, provider, and migrations
- loads package asset routes when `tenancy.routes` is enabled
- boots configured features through `tenancy()->bootstrapFeatures()`
- registers middleware groups: `clone`, `universal`, `tenant`, `central`

## Core Runtime API

`Stancl\Tenancy\Tenancy` exposes:

- `initialize(Tenant|int|string $tenant): void`
- `run(Tenant $tenant, Closure $callback): mixed`
- `central(Closure $callback): mixed`
- `end(): void`
- `reinitialize(): void`
- `bootstrapFeatures(): void`
- `getBootstrappers(): array`
- `find(int|string $id, ?string $column = null, bool $withRelations = false)`

Use these runtime methods instead of rolling your own context switch logic.

## Default Models

From `assets/config.php`:

- `tenancy.models.tenant` => `Stancl\Tenancy\Database\Models\Tenant`
- `tenancy.models.domain` => `Stancl\Tenancy\Database\Models\Domain`
- `tenancy.models.impersonation_token` => `Stancl\Tenancy\Database\Models\ImpersonationToken`

The default tenant key relation column is `tenant_id`.

## Tenant ID Generators

Supported generators exposed in config:

- `UUIDGenerator`
- `ULIDGenerator`
- `UUIDv7Generator`
- `RandomHexGenerator`
- `RandomIntGenerator`
- `RandomStringGenerator`

Set `tenancy.models.id_generator` to `null` only when the app intentionally uses auto-incrementing tenant IDs.

## Identification Middleware

Available middleware:

- `InitializeTenancyByDomain`
- `InitializeTenancyBySubdomain`
- `InitializeTenancyByDomainOrSubdomain`
- `InitializeTenancyByPath`
- `InitializeTenancyByRequestData`
- `InitializeTenancyByOriginHeader`
- `PreventAccessFromUnwantedDomains`
- `CheckTenantForMaintenanceMode`
- `ScopeSessions`

All package identification middleware inherit package failure handling through `IdentificationMiddleware::initializeTenancy()`. Failed identification throws a package exception unless an `onFail` callback is registered.

## Resolvers

Resolvers configured in `tenancy.identification.resolvers`:

- `DomainTenantResolver`
- `PathTenantResolver`
- `RequestDataTenantResolver`

Resolver config includes:

- cache enablement
- cache TTL
- cache store
- path tenant parameter name
- route name prefix
- request header, cookie, and query parameter names
- custom tenant model lookup column

## Default Bootstrappers

Enabled by default:

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

## Bootstrapper Semantics

- `DatabaseTenancyBootstrapper`
  switches the active database connection into tenant context and reverts back to the central connection.
- `CacheTenancyBootstrapper`
  scopes supported cache stores by prefix and can also scope cache-backed sessions.
- `CacheTagsBootstrapper`
  is the older tag-based cache isolation approach and is less complete than prefix-based cache scoping.
- `DatabaseCacheBootstrapper`
  scopes cache by moving database-backed cache stores onto the tenant connection instead of using prefixes.
- `FilesystemTenancyBootstrapper`
  suffixes `storage_path()`, rewrites configured local disk roots, can scope file cache and file sessions, and can enable tenant-aware asset URLs.
- `QueueTenancyBootstrapper`
  injects `tenant_id` into queued job payloads and re-initializes tenancy around queue job execution.
- `PersistentQueueTenancyBootstrapper`
  is the queue bootstrapper variant for cases where worker processes should stay tenant-aware across jobs.
- `DatabaseSessionBootstrapper`
  makes the database session driver use the tenant connection.
- `RedisTenancyBootstrapper`
  sets Redis connection prefixes for configured direct Redis connections.
- `TenantConfigBootstrapper`
  maps tenant attributes into arbitrary config keys during tenancy.
- `RootUrlBootstrapper`
  overrides `app.url` and the URL generator root URL, primarily for CLI URL generation in tenant context.
- `UrlGeneratorBootstrapper`
  swaps in `TenancyUrlGenerator` so route names and tenant parameters are generated correctly for path or query-string identification.
- `MailConfigBootstrapper`
  maps tenant attributes into mail configuration at runtime.
- `BroadcastingConfigBootstrapper`
  maps tenant-specific broadcaster credentials into broadcasting config and swaps in a tenancy-aware broadcast manager.
- `BroadcastChannelPrefixBootstrapper`
  prefixes actual broadcast channel names with the tenant key for supported broadcasters.
- `Bootstrappers\Integrations\FortifyRouteBootstrapper`
  rewrites Fortify redirect targets so tenant auth flows can land on tenant routes.
- `Bootstrappers\Integrations\ScoutPrefixBootstrapper`
  sets `scout.prefix` to the tenant key.
- `PostgresRLSBootstrapper`
  swaps the tenant connection to the configured PostgreSQL RLS user and session variable model.

## Database Isolation Options

The config supports:

- separate tenant databases
- PostgreSQL schema isolation
- optional permission-controlled database managers
- PostgreSQL RLS

Database manager mappings exist for:

- `sqlite`
- `mysql`
- `mariadb`
- `pgsql`
- `sqlsrv`

`tenancy.database.drop_tenant_databases_on_migrate_fresh` controls whether `migrate:fresh` also drops tenant databases through the package override.

## Cache, Filesystem, Queue, And Session Scoping

Important config sections:

- `tenancy.cache`
- `tenancy.filesystem`
- `tenancy.redis`
- `tenancy.migration_parameters`
- `tenancy.seeder_parameters`

Notable filesystem behavior:

- local disks can have tenant-specific root overrides
- `Storage::disk()->url()` can be overridden with tenant-aware public names
- `storage_path()` can be suffixed per tenant
- file cache and file sessions can be scoped
- `asset()` tenancy can be enabled, but may affect packages that assume global assets

## Routes

`assets/routes.php` registers:

- `/tenancy/assets/{path?}` named `stancl.tenancy.asset`
- `/{tenant}/tenancy/assets/{path?}` named `tenant.stancl.tenancy.asset` for path identification, behind the `tenant` middleware

The package route mode enum is `Stancl\Tenancy\Enums\RouteMode`, with central as the default route mode in config.

The service provider also registers empty middleware groups named:

- `clone`
- `universal`
- `tenant`
- `central`

These route modes and middleware groups are part of the package routing model and should be preferred over ad hoc tenant-versus-central route branching.

## Optional Features

Feature classes shipped in `src/Features`:

- `CrossDomainRedirect`
- `DisallowSqliteAttach`
- `TelescopeTags`
- `TenantConfig`
- `UserImpersonation`
- `ViteBundler`

Features bootstrap independently from tenant initialization and are intended to be enabled through `tenancy.features`.

Feature behavior:

- `CrossDomainRedirect`
  adds a `RedirectResponse::domain(string $domain)` macro that swaps the redirect host without rebuilding the whole URL.
- `DisallowSqliteAttach`
  blocks SQLite `ATTACH` usage by registering an authorizer on SQLite PDO connections, using a native authorizer on PHP 8.5+ and a loadable extension fallback on older runtimes.
- `TelescopeTags`
  adds a `tenant:{tenantKey}` Telescope tag when tenancy is initialized.
- `TenantConfig`
  maps tenant attributes into config values using event listeners. This feature is deprecated in favor of `TenantConfigBootstrapper`.
- `UserImpersonation`
  adds a `tenancy()->impersonate()` macro, stores impersonation tokens in the configured impersonation token model, validates token TTL and tenant match, logs the user in with the configured guard, and exposes `isImpersonating()` plus `stopImpersonating()`.
- `ViteBundler`
  configures Vite asset path generation to use `global_asset()` so asset URLs stay central rather than tenant-scoped.

## Pending Tenants

Pending tenant support is configured under `tenancy.pending`.

Important behavior:

- `include_in_queries` controls whether pending tenants are included in normal tenant queries.
- `count` controls the maintained size of the pending-tenant pool.
- the package ships dedicated commands for creating and clearing pending tenants.

If a task touches pre-provisioned tenant pools, inspect the pending-tenant commands and model scopes before implementing custom provisioning logic.

## Commands

Commands registered by `TenancyServiceProvider`:

- `tenancy:install`
- `tenants:up`
- `tenants:run`
- `tenants:down`
- `tenants:link`
- `tenants:seed`
- `tenant:tinker`
- `tenants:migrate`
- `tenants:rollback`
- `tenants:list`
- `tenants:dump`
- `tenants:migrate-fresh`
- `tenants:pending-clear`
- `tenants:pending-create`
- `tenants:purge-impersonation-tokens`
- `tenants:create-user-with-rls-policies`

Prefer these commands over hand-written loops for tenant maintenance tasks.

Command notes:

- `tenancy:install`
  publishes config, routes, service provider, and core migrations, then creates `database/migrations/tenant`.
- `tenants:migrate`
  applies `tenancy.migration_parameters`, supports concurrent execution, and can continue with `--skip-failing`.
- `tenants:rollback`
  rolls back tenant migrations.
- `tenants:migrate-fresh`
  rebuilds tenant schema from scratch.
- `tenants:dump`
  dumps a tenant schema and defaults the dump path from `tenancy.migration_parameters.--schema-path`.
- `tenants:seed`
  uses `tenancy.seeder_parameters`.
- `tenants:run`
  runs arbitrary artisan commands against tenant context.
- `tenant:tinker`
  opens Tinker in a selected tenant context and supports searching by tenant key or domain.
- `tenants:link`
  manages tenant storage symlinks used by tenant-aware public disk URLs.
- `tenants:down` / `tenants:up`
  toggle tenant maintenance mode.
- `tenants:pending-create` / `tenants:pending-clear`
  manage the pending-tenant pool.
- `tenants:purge-impersonation-tokens`
  removes expired impersonation tokens.
- `tenants:rls`
  creates the shared RLS user and row-level-security policies for tenant-related tables.

## Related Subsystems

The package also includes:

- `src/Events/*` for tenancy lifecycle, tenant, domain, database, storage, and pending-tenant events
- `src/Listeners/*` for bootstrapping and reverting context
- `src/Jobs/*` for database and storage lifecycle jobs
- `src/ResourceSyncing/*` for central-to-tenant resource syncing
- `src/RLS/*` for PostgreSQL row-level security support
- `src/Actions/*` for route cloning and storage symlink helpers

When a task touches one of these areas, inspect the relevant namespace before inventing a parallel abstraction in app code.
