---
title: Usage
description: Usage | stancl/tenancy — A Laravel multi-database tenancy package that respects your code..
extends: _layouts.documentation
section: content
---

# Usage {#usage}

This chapter describes usage of the package. That includes creating tenants, deleting tenants, storing data in the tenant storage.

Most pages will use the `tenancy()` helper function. This package comes with two helpers - `tenancy()` and `tenant()`. They do the same thing, so you can use the one that reads better given its context.

`tenant()->create()` reads better than `tenancy()->create()`, but `tenancy()->init()` reads better than `tenant()->init()`.

You can pass an argument to the helper function to get a value out of the tenant storage. `tenant('plan')` is identical to [`tenant()->get('plan')`](/docs/tenant-storage).

The package also comes with two facades. `Tenancy` and `Tenant`. Use what feels the best.

Both the helpers and the facades resolve the `TenantManager` from the service container.