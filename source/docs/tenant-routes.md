---
title: Tenant Routes
description: Tenant routes with stancl/tenancy — A Laravel multi-database tenancy package that respects your code..
extends: _layouts.documentation
section: content
---

# Tenant Routes {#tenant-routes}

Routes within `routes/tenant.php` will have the `web` middleware group and the `IntializeTenancy` middleware automatically applied on them. This middleware attempts to identify the tenant based on the current hostname. Once the tenant is identified, the database connection, cache, filesystem root paths and, optionally, Redis connection, will be switched.

Just like `routes/web.php`, these routes use the `App\Http\Controllers` namespace.

> If a tenant cannot be identified,  anexception will be thrown. If you want to change this behavior (to a redirect, for example) read the [Middleware Configuration](/docs/middleware-configuration) page.

## Exempt routes {#exempt-routes}

Routes outside the `routes/tenant.php` file will not have the tenancy middleware automatically applied on them. You can apply this middleware manually, though.

If you want some of your, say, API routes to be multi-tenant, simply wrap them in a Route group with this middleware:

```php
use Stancl\Tenancy\Middleware\InitializeTenancy;

Route::middleware(InitializeTenancy::class)->group(function () {
    // Route::get('/', 'HelloWorld');
});
```

## Using the same routes for tenant and non-tenant parts of the application {#using-the-same-routes-for-tenant-and-non-tenant-parts-of-the-application}

The `Stancl\Tenancy\Middleware\PreventAccessFromTenantDomains` middleware makes sure 404 is returned when a user attempts to visit a web route on a tenant (non-exempt) domain.

The install command applies this middleware to the `web` group. If you want to do this for another route group, add this middleware manually to that group. You can do this in `app/Http/Kernel.php`.