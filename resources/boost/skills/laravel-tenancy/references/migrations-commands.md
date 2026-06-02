# Migrations And Commands Reference

Use this for tenant-aware Artisan operations.

## Source Files

- `src/Commands/*`
- `src/Concerns/HasTenantOptions.php`
- `src/Concerns/ExtendsLaravelCommand.php`
- `src/Concerns/DealsWithMigrations.php`
- `assets/config.php`

## Tenant Migrations

Defaults from `tenancy.migration_parameters`:

- `--force` true
- `--path` `database/migrations/tenant`
- `--schema-path` `database/schema/tenant-schema.dump`
- `--realpath` true

Commands:

```bash
php artisan tenants:migrate
php artisan tenants:migrate --tenants=tenant-id
php artisan tenants:migrate --skip-failing
php artisan tenants:rollback
php artisan tenants:migrate-fresh
php artisan tenants:seed
```

## Tenant Operations

```bash
php artisan tenants:run cache:clear
php artisan tenant:tinker
php artisan tenants:list
php artisan tenants:dump
```

## Maintenance And Storage

```bash
php artisan tenants:down
php artisan tenants:up
php artisan tenants:link
php artisan tenants:link --remove
```

## Rules

- Use tenant commands for tenant DBs; normal `migrate` is central.
- Use `--tenants=*` to scope commands to selected tenants.
- Use `--skip-failing` only when failures should not stop execution.
- Use concurrent process options only after verifying tenant operations are safe in parallel.
