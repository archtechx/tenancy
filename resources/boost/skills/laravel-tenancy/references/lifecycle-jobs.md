# Lifecycle Jobs Reference

Use this when provisioning or deleting tenant resources through events and job pipelines.

## Source Files

- `assets/TenancyServiceProvider.stub.php`
- `src/Events/*`
- `src/Jobs/*`
- `src/Listeners/*`
- `src/Database/Models/Tenant.php`
- `src/Database/Models/Domain.php`

## Tenant Created Pipeline

The published application provider wires `TenantCreated` to a `JobPipeline` containing:

- `CreateDatabase`
- `MigrateDatabase`
- optional `SeedDatabase`
- optional `CreateStorageSymlinks`
- custom provisioning jobs

## Tenant Deletion Pipelines

The stub wires:

- `DeletingTenant` to `DeleteDomains`, optional `DeleteTenantStorage`, optional `RemoveStorageSymlinks`.
- `TenantDeleted` to `DeleteDatabase`, optional resource-syncing cleanup.

## Event Groups

- Tenant lifecycle events.
- Domain lifecycle events.
- Database lifecycle events.
- Tenancy initialization/end/bootstrap events.
- Pending tenant events.
- Resource syncing events.
- Storage symlink events.

## Rules

- Put provisioning and cleanup in event pipelines rather than controllers.
- Keep database, migration, seeding, storage, domain, and sync cleanup order explicit.
- Decide whether pipelines should be queued using `shouldBeQueued()`.
- Test tenant creation and deletion side effects.
