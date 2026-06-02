# User Impersonation Reference

Use this when implementing tenant user impersonation.

## Source Files

- `src/Features/UserImpersonation.php`
- `src/Database/Models/ImpersonationToken.php`
- `assets/impersonation-migrations/*`

## Setup

```bash
php artisan vendor:publish --provider="Stancl\Tenancy\TenancyServiceProvider" --tag=impersonation-migrations
php artisan migrate
```

Enable feature:

```php
'features' => [
    Stancl\Tenancy\Features\UserImpersonation::class,
],
```

## Command

```bash
php artisan tenants:purge-impersonation-tokens
```

## Rules

- Verify tenant match before logging in impersonated users.
- Test guard, redirect URL, remember flag, token TTL, and invalid token behavior.
- Purge expired tokens routinely.
