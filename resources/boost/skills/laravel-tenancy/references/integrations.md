# Integrations Reference

Use this when tenancy integrates with URL generation, mail, broadcasting, Fortify, Scout, Livewire, Telescope, or Vite.

## Source Files

- `src/Bootstrappers/RootUrlBootstrapper.php`
- `src/Bootstrappers/UrlGeneratorBootstrapper.php`
- `src/Bootstrappers/MailConfigBootstrapper.php`
- `src/Bootstrappers/BroadcastingConfigBootstrapper.php`
- `src/Bootstrappers/BroadcastChannelPrefixBootstrapper.php`
- `src/Bootstrappers/Integrations/FortifyRouteBootstrapper.php`
- `src/Bootstrappers/Integrations/ScoutPrefixBootstrapper.php`
- `src/Features/TelescopeTags.php`
- `src/Features/ViteBundler.php`
- `assets/TenancyServiceProvider.stub.php`

## Bootstrappers

- `RootUrlBootstrapper`: tenant root URL for CLI/context URL generation.
- `UrlGeneratorBootstrapper`: tenant-aware route names and tenant parameters.
- `MailConfigBootstrapper`: tenant-specific mail config.
- `BroadcastingConfigBootstrapper`: tenant broadcaster config and manager.
- `BroadcastChannelPrefixBootstrapper`: tenant-prefixed broadcast channel names.
- `FortifyRouteBootstrapper`: tenant auth route/redirect integration.
- `ScoutPrefixBootstrapper`: tenant-specific Scout prefix.

## Features

- `TelescopeTags`: adds tenant tags when tenancy is initialized.
- `ViteBundler`: tenant-aware bundling behavior.

## Stub Hooks

- `overrideUrlInTenantContext()` shows how to set `RootUrlBootstrapper::$rootUrlOverride`.
- The Livewire v3 comment shows how to make the Livewire update route universal.
- `cloneRoutes()` shows the package route-cloning integration point.

## Rules

- Prefer bootstrappers over ad hoc service-provider config mutation.
- Test generated URLs in HTTP and CLI contexts.
- Test broadcast channel names and tenant-specific broadcaster credentials.
- Make third-party package routes universal or cloned only when intentionally accessible in tenant context.
