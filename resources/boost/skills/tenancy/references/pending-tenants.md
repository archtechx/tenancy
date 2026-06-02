# Pending Tenants Reference

Use this when maintaining a pool of prepared tenants.

## Source Files

- `src/Commands/CreatePendingTenants.php`
- `src/Commands/ClearPendingTenants.php`
- `src/Database/Concerns/HasPending.php`
- `src/Database/Concerns/PendingScope.php`
- `src/Jobs/CreatePendingTenants.php`
- `src/Jobs/ClearPendingTenants.php`

## Config

- `pending.include_in_queries`
- `pending.count`, defaulting to `TENANCY_PENDING_COUNT` or 5

## Commands

```bash
php artisan tenants:pending-create
php artisan tenants:pending-create --count=10
php artisan tenants:pending-clear
php artisan tenants:pending-clear --older-than-days=7
php artisan tenants:pending-clear --older-than-hours=12
```

## Rules

- When `include_in_queries` is false, pending tenants are excluded from tenant queries and tenant commands.
- Use `withPending()`, `withoutPending()`, and `onlyPending()` intentionally.
- Test command behavior with and without pending tenants included in queries.
