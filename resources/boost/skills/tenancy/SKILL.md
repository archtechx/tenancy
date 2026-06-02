---
name: tenancy
description: "Activate when the user is building or debugging multi-tenant Laravel behavior with stancl/tenancy. Use for tenancy:install, tenant identification middleware, central and tenant routes, tenant model and domain model setup, multi-database or single-database tenancy, tenant-aware bootstrappers for database/cache/filesystem/queue/session/Redis, tenant context switching with tenancy()->initialize() or tenant()->run(), tenant migrations and seeders, tenant asset routes, pending tenants, resource syncing, user impersonation, RLS, Vite bundling, or testing tenant-aware behavior."
license: MIT
metadata:
  author: Samuel Štancl
---

# Tenancy For Laravel

Use this skill when a Laravel task involves `stancl/tenancy`.

## Documentation

Use `search-docs` first when it is available for Laravel integration patterns. For package-specific behavior, inspect:

- `src/TenancyServiceProvider.php`
- `src/Tenancy.php`
- `assets/config.php`
- `assets/routes.php`
- `src/Middleware/*`
- `src/Bootstrappers/*`
- `src/Commands/*`
- `src/Database/Models/*`
- `src/Resolvers/*`
- `src/Features/*`
- `references/package.md`

Load `references/package.md` when the task needs package-specific detail beyond the core workflow in this file.

## Feature References

Load focused references when the task matches a specific package area:

- `references/installation.md` for install, publishing, and setup checks
- `references/configuration.md` for `config/tenancy.php` sections
- `references/identification.md` for middleware and resolvers
- `references/routing-assets.md` for tenant routes, route modes, cloned routes, and asset routes
- `references/context-api.md` for `tenancy()`, `tenant()`, `run()`, and `central()` behavior
- `references/bootstrappers.md` for tenant-aware Laravel service scoping
- `references/database-tenancy.md` for database isolation and tenant database managers
- `references/migrations-commands.md` for tenant Artisan commands
- `references/models-domains.md` for tenant/domain models and single-database traits
- `references/filesystem-cache-queue.md` for storage, cache, sessions, Redis, and queues
- `references/lifecycle-jobs.md` for events, provisioning, and cleanup pipelines
- `references/resource-syncing.md` for synced central and tenant resources
- `references/impersonation.md` for tenant user impersonation
- `references/pending-tenants.md` for pending tenant pools
- `references/rls.md` for PostgreSQL row-level security
- `references/features.md` for optional package features
- `references/integrations.md` for URL, mail, broadcasting, Fortify, Scout, Livewire, Telescope, and Vite
- `references/testing.md` for test coverage guidance

## Package Surface

The package auto-discovers:

- Service provider: `Stancl\Tenancy\TenancyServiceProvider`
- Facades: `Tenancy` and `GlobalCache`

The package also publishes:

- `config/tenancy.php`
- `routes/tenant.php`
- `app/Providers/TenancyServiceProvider.php`
- tenant, domain, impersonation, and resource-syncing migrations

## Installation And Setup

Install the package with Composer:

```bash
composer require stancl/tenancy
```

Prefer the package installer over manual publishing:

```bash
php artisan tenancy:install --no-interaction
```

That command publishes the config, routes, provider, core migrations, and creates `database/migrations/tenant`.

## Core Working Pattern

1. Install the package and inspect `config/tenancy.php`.
2. Decide the tenant identification strategy first: domain, subdomain, domain-or-subdomain, path, request data, or origin header.
3. Keep central and tenant routes explicit. Use the package middleware and route modes instead of ad hoc request checks.
4. Choose the minimum bootstrapper set that matches the app's infrastructure.
5. For data isolation, decide between multi-database tenancy, single-database tenancy, or PostgreSQL RLS before writing application models.
6. Test both central and tenant contexts.

## Tenant Identification

The default identification middleware is `InitializeTenancyByDomain`.

Available identification middleware:

- `InitializeTenancyByDomain`
- `InitializeTenancyBySubdomain`
- `InitializeTenancyByDomainOrSubdomain`
- `InitializeTenancyByPath`
- `InitializeTenancyByRequestData`
- `InitializeTenancyByOriginHeader`

Use `PreventAccessFromUnwantedDomains` only with the domain-oriented identification middleware recognized by the package config.

For path identification, the package uses `PathTenantResolver` and a route parameter name configured in `tenancy.identification.resolvers`.

## Tenant Context

Common patterns:

```php
tenancy()->initialize($tenant);

$tenant->run(function () {
    // Code in tenant context.
});

tenancy()->central(function () {
    // Code in central context.
});
```

Prefer the package context helpers instead of manually mutating connections, cache prefixes, filesystem roots, or config.

## Bootstrappers

Default bootstrappers cover:

- tenant database connection switching
- cache scoping
- filesystem scoping
- queue scoping
- database session support

Optional bootstrappers exist for:

- Redis scoping
- database-backed cache scoping
- tenant config injection
- URL, root URL, and asset generation
- mail config
- broadcasting config and channel prefixing
- Fortify and Scout integration
- PostgreSQL RLS

If tenant state appears partially applied, inspect the active bootstrappers in `config/tenancy.php` before changing application code.

## Routes And Assets

The package registers route middleware groups for `clone`, `universal`, `tenant`, and `central`.

When tenancy routes are enabled, it registers tenant asset routes from `assets/routes.php`. For path-based identification, asset routes can include the tenant parameter prefix.

Keep tenant routes in `routes/tenant.php` when the app uses the published route stub.

## Data Model Guidance

The default tenant model is `Stancl\Tenancy\Database\Models\Tenant`.

Related package models:

- `Stancl\Tenancy\Database\Models\Domain`
- `Stancl\Tenancy\Database\Models\ImpersonationToken`

Use package traits and helpers before inventing your own tenant key, domain, or tenant-run abstractions.

When changing tenant identity generation, use a class implementing `UniqueIdentifierGenerator` or set the generator to `null` only if the application intentionally uses auto-incrementing IDs.

## Commands

Common package commands:

- `tenancy:install`
- `tenants:migrate`
- `tenants:rollback`
- `tenants:seed`
- `tenants:run`
- `tenant:tinker`
- `tenants:list`
- `tenants:down`
- `tenants:up`
- `tenants:link`

Use the package commands for tenant-aware migration, seeding, maintenance, and per-tenant execution instead of custom loops.

## Features

Optional features include:

- `UserImpersonation`
- `TelescopeTags`
- `CrossDomainRedirect`
- `ViteBundler`
- `DisallowSqliteAttach`
- `TenantConfig`

Enable features through `tenancy.features` and check each feature's class before assuming it changes bootstrapping behavior.

## Testing

Test both successful identification and failure behavior.

For tenancy-aware tests, verify:

- the request resolves the correct tenant
- central routes stay central
- tenant routes reject central access when expected
- tenant context affects database, cache, filesystem, queue, and URL behavior as intended
- tenant artisan commands run against the expected tenants

If a behavior depends on package internals, inspect `tests/*` in the package or load `references/package.md` before adding application-level workarounds.
