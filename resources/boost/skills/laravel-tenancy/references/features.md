# Optional Features Reference

Use this when enabling or debugging classes in `tenancy.features`.

## Source Files

- `src/Features/*`
- `src/Tenancy.php`

## Features

- `UserImpersonation`
- `TelescopeTags`
- `CrossDomainRedirect`
- `ViteBundler`
- `DisallowSqliteAttach`
- `TenantConfig`

## Behavior

- Features are bootstrapped independently from tenant initialization.
- `tenancy()->bootstrapFeatures()` is idempotent per feature.
- Feature bootstrapping is irreversible during the request lifecycle.
- `TenantConfig` is deprecated in favor of `TenantConfigBootstrapper`.

## Rules

- Inspect the feature class before assuming behavior.
- Enable only the needed features.
- Test each enabled feature in central and tenant contexts where relevant.
