# Routing And Assets Reference

Use this when working with tenant routes, route modes, cloned routes, or tenant assets.

## Source Files

- `assets/tenant_routes.stub.php`
- `assets/TenancyServiceProvider.stub.php`
- `assets/routes.php`
- `src/Actions/CloneRoutesAsTenant.php`
- `src/Enums/RouteMode.php`
- `src/Controllers/TenantAssetController.php`

## Published Tenant Routes

The stub groups tenant routes with:

- `web`
- `InitializeTenancyByDomain`
- `PreventAccessFromUnwantedDomains`
- `ScopeSessions`

The application `TenancyServiceProvider` loads `routes/tenant.php` under the `tenant` middleware group.

## Route Modes

The package registers these middleware groups:

- `clone`
- `universal`
- `tenant`
- `central`

`tenancy.default_route_mode` defaults to central. Override per route using route mode middleware.

## Asset Routes

When `tenancy.routes` is true, the package registers:

- `/tenancy/assets/{path?}` named `stancl.tenancy.asset`
- `/{tenant}/tenancy/assets/{path?}` named `tenant.stancl.tenancy.asset` for path identification

## Rules

- Keep central and tenant routes explicit.
- Use `routes/tenant.php` for tenant application routes when using the stub.
- Use `universal` only for routes intended to work in both contexts.
- Use `CloneRoutesAsTenant` for package route integration instead of manually duplicating route definitions.
- Disable package routes only if using external storage or a custom asset controller.
