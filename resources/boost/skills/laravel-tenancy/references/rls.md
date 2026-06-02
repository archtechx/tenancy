# PostgreSQL RLS Reference

Use this when implementing single-database PostgreSQL row-level security.

## Source Files

- `src/Bootstrappers/PostgresRLSBootstrapper.php`
- `src/RLS/*`
- `src/Database/Concerns/RLSModel.php`
- `src/Commands/CreateUserWithRLSPolicies.php`
- `tests/RLS/*`

## Config

- `rls.manager`
- `rls.user.username`
- `rls.user.password`
- `rls.session_variable_name`
- `PostgresRLSBootstrapper` in `bootstrappers`

## Command

```bash
php artisan tenants:rls
php artisan tenants:rls --force
```

## Rules

- Use PostgreSQL and single-database tenancy.
- Session variable name must be namespaced, for example `my.current_tenant`.
- RLS user is one tenant database user for all tenants, not one user per tenant.
- Test policies on every tenant-owned table.
