# Filesystem Cache Queue Reference

Use this when tenant context affects storage, cache, sessions, Redis, or queues.

## Source Files

- `src/Bootstrappers/FilesystemTenancyBootstrapper.php`
- `src/Bootstrappers/CacheTenancyBootstrapper.php`
- `src/Bootstrappers/CacheTagsBootstrapper.php`
- `src/Bootstrappers/DatabaseCacheBootstrapper.php`
- `src/Bootstrappers/RedisTenancyBootstrapper.php`
- `src/Bootstrappers/QueueTenancyBootstrapper.php`
- `src/Bootstrappers/PersistentQueueTenancyBootstrapper.php`
- `src/Bootstrappers/DatabaseSessionBootstrapper.php`
- `src/Middleware/ScopeSessions.php`

## Filesystem

- `filesystem.disks` controls scoped disks.
- `filesystem.root_override` rewrites local disk roots.
- `filesystem.url_override` enables tenant-aware local public URLs.
- `filesystem.suffix_storage_path` controls `storage_path()` suffixing.
- `filesystem.asset_helper_override` makes `asset()` tenant-aware.

## Cache And Redis

- Cache tenancy prefixes configured stores through `cache.prefix`.
- Global central cache is available through `GlobalCache`/`globalCache()`.
- Redis tenancy is for direct Redis usage and requires phpredis.

## Queue

- Queue bootstrapper carries tenant context into queued jobs.
- Persistent queue bootstrapper is for workers intentionally staying tenant-aware.

## Rules

- Run `php artisan tenants:link` when tenant public local storage URLs are enabled.
- Be careful with `asset_helper_override`; third-party package assets may become tenant-aware.
- Scope sessions through config and `ScopeSessions` middleware where required.
- Test queued jobs in central and tenant contexts.
