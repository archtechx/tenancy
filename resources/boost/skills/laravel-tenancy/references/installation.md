# Installation Reference

Use this when installing or auditing `stancl/tenancy` setup.

## Source Files

- `src/Commands/Install.php`
- `src/TenancyServiceProvider.php`
- `assets/config.php`
- `assets/tenant_routes.stub.php`
- `assets/TenancyServiceProvider.stub.php`
- `assets/migrations/*`

## Required Steps

1. Install the package.

```bash
composer require stancl/tenancy
```

1. Run the installer non-interactively.

```bash
php artisan tenancy:install --no-interaction
```

1. Confirm these files exist:

- `config/tenancy.php`
- `routes/tenant.php`
- `app/Providers/TenancyServiceProvider.php`
- `database/migrations/2019_09_15_000010_create_tenants_table.php`
- `database/migrations/2019_09_15_000020_create_domains_table.php`
- `database/migrations/tenant`

1. Review `config/tenancy.php` before running migrations.

1. Run central migrations.

```bash
php artisan migrate
```

1. Add tenant migrations to `database/migrations/tenant`.

1. Create tenants and domains according to the identification strategy.

1. Run tenant migrations.

```bash
php artisan tenants:migrate
```

## Manual Publish Commands

Prefer `tenancy:install`. Use these only for targeted publishing:

```bash
php artisan vendor:publish --provider="Stancl\Tenancy\TenancyServiceProvider" --tag=config
php artisan vendor:publish --provider="Stancl\Tenancy\TenancyServiceProvider" --tag=routes
php artisan vendor:publish --provider="Stancl\Tenancy\TenancyServiceProvider" --tag=providers
php artisan vendor:publish --provider="Stancl\Tenancy\TenancyServiceProvider" --tag=migrations
php artisan vendor:publish --provider="Stancl\Tenancy\TenancyServiceProvider" --tag=impersonation-migrations
php artisan vendor:publish --provider="Stancl\Tenancy\TenancyServiceProvider" --tag=resource-syncing-migrations
```

## Installer Behavior

- Publishes config, routes, provider, and base migrations.
- Creates `database/migrations/tenant`.
- Skips files that already exist and warns instead of overwriting.
- Shows an interactive support prompt unless `--no-interaction` is used.
