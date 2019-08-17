---
title: Creating Tenants
description: Creating tenants with stancl/tenancy — A Laravel multi-database tenancy package that respects your code..
extends: _layouts.documentation
section: content
---

# Creating Tenants {#creating-tenants}

> **Make sure your database is correctly [configured](/docs/configuration/#database) before creating tenants.**

To create a tenant, you can use

```php
tenant()->create('tenant1.yourapp.com');
```

> Tip: All domains under `.localhost` are routed to 127.0.0.1 on most operating systems. This is useful for development.

If you want to set some data while creating the tenant, you can pass an array with the data as the second argument:

```php
tenant()->create('tenant2.yourapp.com', [
    'plan' => 'free'
]);
```

The `create` method returns an array with tenant information (`uuid`, `domain` and whatever else you supplied).

> Note: Creating a tenant doesn't run [migrations](https://stancl-tenancy.netlify.com/docs/console-commands/#migrate) automatically. You have to do that yourself.