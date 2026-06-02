# Bootstrappers Reference

Use this when tenant context should affect Laravel services.

## Source Files

- `src/Bootstrappers/*`
- `assets/config.php`

## Defaults

- `DatabaseTenancyBootstrapper`
- `CacheTenancyBootstrapper`
- `FilesystemTenancyBootstrapper`
- `QueueTenancyBootstrapper`
- `DatabaseSessionBootstrapper`

## Optional Bootstrappers

- `CacheTagsBootstrapper`
- `DatabaseCacheBootstrapper`
- `RedisTenancyBootstrapper`
- `TenantConfigBootstrapper`
- `RootUrlBootstrapper`
- `UrlGeneratorBootstrapper`
- `MailConfigBootstrapper`
- `BroadcastingConfigBootstrapper`
- `BroadcastChannelPrefixBootstrapper`
- `FortifyRouteBootstrapper`
- `ScoutPrefixBootstrapper`
- `PostgresRLSBootstrapper`
- `PersistentQueueTenancyBootstrapper`

## Rules

- Configure bootstrappers before writing app-level workarounds.
- `DatabaseCacheBootstrapper` must run after `DatabaseTenancyBootstrapper`.
- `RedisTenancyBootstrapper` needs phpredis and is for direct Redis calls.
- Prefer `TenantConfigBootstrapper` over deprecated `TenantConfig` feature.
- Use `RootUrlBootstrapper` for CLI URL root behavior.
- Use `UrlGeneratorBootstrapper` for tenant-aware route generation.
- Inspect `tenancy()->getBootstrappers()` when context looks partially applied.
