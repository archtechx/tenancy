<stancl-tenancy-guidelines>
=== core rules ===

# Stancl Tenancy Guidelines

These guidelines apply to Laravel applications using `stancl/tenancy`. Keep this file concise for Laravel Boost. For detailed package behavior, load the focused references in `resources/boost/skills/laravel-tenancy/references`.

## Package Context

- Composer package: `stancl/tenancy`
- Service provider: `Stancl\Tenancy\TenancyServiceProvider`
- Facades: `Tenancy` and `GlobalCache`
- Core manager: `Stancl\Tenancy\Tenancy`
- Default tenant model: `Stancl\Tenancy\Database\Models\Tenant`
- Default domain model: `Stancl\Tenancy\Database\Models\Domain`
- Default tenant key column: `tenant_id`
- Reserved dynamic tenant connection name: `tenant`

## Focused References

Load the focused reference matching the task before implementing changes:

- `resources/boost/skills/laravel-tenancy/references/installation.md` for install and publishing
- `resources/boost/skills/laravel-tenancy/references/configuration.md` for `config/tenancy.php`
- `resources/boost/skills/laravel-tenancy/references/identification.md` for middleware and resolvers
- `resources/boost/skills/laravel-tenancy/references/routing-assets.md` for routes, route modes, cloned routes, and tenant assets
- `resources/boost/skills/laravel-tenancy/references/context-api.md` for `tenancy()`, `tenant()`, `run()`, and `central()`
- `resources/boost/skills/laravel-tenancy/references/bootstrappers.md` for tenant-aware Laravel service scoping
- `resources/boost/skills/laravel-tenancy/references/database-tenancy.md` for database isolation and database managers
- `resources/boost/skills/laravel-tenancy/references/migrations-commands.md` for tenant Artisan commands
- `resources/boost/skills/laravel-tenancy/references/models-domains.md` for tenant/domain models and single-database traits
- `resources/boost/skills/laravel-tenancy/references/filesystem-cache-queue.md` for storage, cache, sessions, Redis, and queues
- `resources/boost/skills/laravel-tenancy/references/lifecycle-jobs.md` for tenant event pipelines and provisioning
- `resources/boost/skills/laravel-tenancy/references/resource-syncing.md` for synced central and tenant resources
- `resources/boost/skills/laravel-tenancy/references/impersonation.md` for tenant user impersonation
- `resources/boost/skills/laravel-tenancy/references/pending-tenants.md` for pending tenant pools
- `resources/boost/skills/laravel-tenancy/references/rls.md` for PostgreSQL row-level security
- `resources/boost/skills/laravel-tenancy/references/features.md` for optional package features
- `resources/boost/skills/laravel-tenancy/references/integrations.md` for URL, mail, broadcasting, Fortify, Scout, Livewire, Telescope, and Vite
- `resources/boost/skills/laravel-tenancy/references/testing.md` for test coverage guidance

## Installation Rules

Use the package installer unless intentionally publishing one tag:

```bash
composer require stancl/tenancy
php artisan tenancy:install --no-interaction
php artisan migrate
php artisan tenants:migrate
```

The installer publishes:

- `config/tenancy.php`
- `routes/tenant.php`
- `app/Providers/TenancyServiceProvider.php`
- central tenant and domain migrations
- `database/migrations/tenant`

Review `config/tenancy.php` before running migrations. Decide identification, central domains, route mode, bootstrappers, database isolation, and tenant migration path before writing application code.

## Core Workflow

- Decide tenant identification first: domain, subdomain, domain-or-subdomain, path, request data, or origin header.
- Keep central, tenant, and universal routes explicit with package middleware and route modes.
- Choose database isolation early: separate tenant databases, PostgreSQL schemas, single-database tenancy, or PostgreSQL RLS.
- Configure bootstrappers before writing application workarounds.
- Put tenant migrations in `database/migrations/tenant` unless `tenancy.migration_parameters` changes the path.
- Use package context APIs instead of manually mutating framework state.
- Test central and tenant contexts for every tenancy-sensitive change.

## Tenant Context Rules

Use package APIs:

```php
tenancy()->initialize($tenant);
$tenant->run(fn () => null);
tenancy()->central(fn () => null);
tenancy()->end();
```

Do not manually change database connections, cache prefixes, filesystem roots, queue payloads, session stores, URL roots, mail config, or broadcasting config when a package bootstrapper owns that concern.

## Identification Rules

Built-in tenant identification middleware:

- `InitializeTenancyByDomain`
- `InitializeTenancyBySubdomain`
- `InitializeTenancyByDomainOrSubdomain`
- `InitializeTenancyByPath`
- `InitializeTenancyByRequestData`
- `InitializeTenancyByOriginHeader`

Use `PreventAccessFromUnwantedDomains` only with domain-oriented identification middleware recognized by `tenancy.identification.domain_identification_middleware`.

For path identification, check `PathTenantResolver` config before changing route parameters. For request-data identification, configure header, cookie, and query parameter names explicitly.

## Routing Rules

The package registers route middleware groups:

- `clone`
- `universal`
- `tenant`
- `central`

Use `routes/tenant.php` for tenant application routes when using the published stub. Use `CloneRoutesAsTenant` for package route integration instead of manually duplicating package routes.

When `tenancy.routes` is true, tenant asset routes are loaded from `assets/routes.php`. If `filesystem.url_override` is enabled for local public disks, run:

```bash
php artisan tenants:link
```

## Database And Model Rules

- Never use `tenant` as a template tenant connection name.
- Add tenant-specific migrations to `database/migrations/tenant`.
- Use tenant commands for tenant databases, not normal Laravel migration commands.
- If using auto-increment tenant IDs, set `models.id_generator` to `null` and update the tenants migration together.
- Custom tenant models must implement `Stancl\Tenancy\Contracts\Tenant`.
- Custom domain models must implement `Stancl\Tenancy\Contracts\Domain`.
- Use package traits for single-database tenancy instead of hand-written tenant filters.

## Command Rules

Use package commands for tenant-aware operations:

```bash
php artisan tenants:migrate
php artisan tenants:rollback
php artisan tenants:migrate-fresh
php artisan tenants:seed
php artisan tenants:run cache:clear
php artisan tenant:tinker
php artisan tenants:dump
php artisan tenants:list
php artisan tenants:down
php artisan tenants:up
php artisan tenants:link
php artisan tenants:pending-create
php artisan tenants:pending-clear
php artisan tenants:purge-impersonation-tokens
php artisan tenants:rls
```

Use `php artisan help <command>` to confirm options in the installed app. Use `--tenants=*` when only selected tenants should be affected.

## Feature Rules

Enable optional features through `tenancy.features` only when needed:

- `UserImpersonation`
- `TelescopeTags`
- `CrossDomainRedirect`
- `ViteBundler`
- `DisallowSqliteAttach`
- `TenantConfig`

Prefer `TenantConfigBootstrapper` over the deprecated `TenantConfig` feature for mapping tenant attributes into config. Publish and migrate impersonation/resource-syncing migrations before enabling those workflows.

## Testing Rules

Every tenancy behavior change needs tests covering the relevant central and tenant contexts.

Verify the task-specific behavior:

- tenant identification success and failure
- central routes stay central
- tenant routes initialize tenancy
- database, cache, filesystem, queue, session, URL, mail, or broadcasting scoping
- tenant migrations, seeds, and command options
- tenant lifecycle jobs and event pipelines
- optional features only when enabled
- context restoration after `run()` and `central()` callbacks

## Common Pitfalls

- Running normal `php artisan migrate` and expecting tenant databases to migrate
- Forgetting `php artisan tenants:migrate` after tenants exist
- Mixing central and tenant routes without route mode decisions
- Using `PreventAccessFromUnwantedDomains` with non-domain identification
- Forgetting `identification.central_domains` for domain/subdomain apps
- Manually changing framework state instead of using bootstrappers
- Enabling resolver caching without invalidation coverage
- Enabling `filesystem.asset_helper_override` without checking third-party asset calls
- Using single-database tenancy without consistent tenant scoping
- Skipping central-context tests after adding tenant behavior

</stancl-tenancy-guidelines>
