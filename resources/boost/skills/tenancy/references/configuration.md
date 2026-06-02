# Configuration Reference

Use this when changing `config/tenancy.php`.

## Source Files

- `assets/config.php`
- `src/TenancyServiceProvider.php`

## Must-Review Sections

- `models`: tenant, domain, impersonation token, tenant key column, ID generator.
- `identification`: central domains, default middleware, resolver settings.
- `bootstrappers`: runtime Laravel feature scoping.
- `database`: central connection, template tenant connection, DB managers, prefixes.
- `rls`: PostgreSQL RLS manager, user, and session variable.
- `cache`: prefix, stores, session scoping, tag base.
- `filesystem`: disks, root overrides, URL overrides, storage suffixing, asset override.
- `redis`: direct Redis connection prefixing.
- `features`: optional package features.
- `routes`: package asset route registration toggle.
- `default_route_mode`: central, tenant, or universal default route behavior.
- `pending`: pending tenant query inclusion and pool count.
- `migration_parameters`: tenant migration defaults.
- `seeder_parameters`: tenant seeder defaults.

## Rules

- Decide identification, database isolation, and bootstrappers before writing app code.
- Never use `tenant` as the template tenant connection name; it is reserved by the package.
- Set `models.id_generator` to `null` only when using auto-increment tenant IDs intentionally.
- Keep resolver caching disabled until invalidation behavior is tested.
- Keep `database.drop_tenant_databases_on_migrate_fresh` false unless local/dev destructive behavior is intended.
- Enable `filesystem.asset_helper_override` only after checking third-party package asset calls.
