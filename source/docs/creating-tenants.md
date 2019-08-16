---
title: Creating Tenants
description: Creating tenants with stancl/tenancy — A Laravel multi-database tenancy package that respects your code..
extends: _layouts.documentation
section: content
---

# Creating Tenants {#creating-tenants}

To create a tenant, you can use

```php
tenant()->create('tenant1.yourapp.com');
```

If you want to set some data while creating the tenant, you can pass an array with the data as the second argument:

```php
tenant()->create('tenant2.yourapp.com', [
    'plan' => 'free'
]);
```