# Resource Syncing Reference

Use this when syncing central resources into tenant contexts.

## Source Files

- `src/ResourceSyncing/*`
- `assets/resource-syncing-migrations/*`
- `assets/TenancyServiceProvider.stub.php`

## Setup

Publish resource syncing migration:

```bash
php artisan vendor:publish --provider="Stancl\Tenancy\TenancyServiceProvider" --tag=resource-syncing-migrations
php artisan migrate
```

## Main Pieces

- `tenant_resources` table.
- `ResourceSyncing` classes and listeners.
- `SyncMaster`, `Syncable`, `TenantPivot`, `TenantMorphPivot`.
- Events such as `SyncedResourceSaved`, `SyncedResourceDeleted`, `CentralResourceAttachedToTenant`.

## Rules

- Use package events/listeners instead of custom tenant loops.
- Keep central and tenant resource lifecycles explicit.
- Configure soft-delete query behavior in the application `TenancyServiceProvider` when needed.
- Test create, update, delete, restore, attach, and detach behavior.
