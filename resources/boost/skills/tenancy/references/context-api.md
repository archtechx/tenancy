# Context API Reference

Use this when switching between tenant and central contexts.

## Source Files

- `src/Tenancy.php`
- `src/helpers.php`
- `src/Database/Concerns/TenantRun.php`
- `src/Database/Concerns/InitializationHelpers.php`

## Core API

```php
tenancy()->initialize($tenant);
tenancy()->end();
tenancy()->reinitialize();
tenancy()->central(fn () => null);
$tenant->run(fn () => null);
```

## Behavior

- `initialize()` accepts a tenant model, ID, or string key.
- Switching tenants ends the previous context first.
- `run()` restores the previous tenant or central context in `finally`.
- `central()` temporarily ends tenancy and restores prior tenant context.
- `reinitialize()` re-runs bootstrappers for the current tenant.
- `bootstrapFeatures()` is idempotent per feature, but feature bootstrapping is irreversible.
- `find()` resolves tenants through the configured tenant model.

## Helpers

- `tenancy()` returns the tenancy singleton.
- `tenant()` returns current tenant or tenant attribute.
- `central()` executes a callback in central context.
- `globalCache()` resolves central cache.
- `tenant_asset()` returns tenant asset URLs.
- `global_asset()` returns global asset URLs.

## Rules

- Do not manually mutate DB connections, cache prefixes, filesystem roots, queue payloads, sessions, or URL roots.
- Use atomic `run()` and `central()` when context must be restored safely.
- Test context restoration after exceptions.
