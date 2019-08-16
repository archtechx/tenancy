---
title: Tenant Storage
description: Tenant storage with stancl/tenancy — A Laravel multi-database tenancy package that respects your code..
extends: _layouts.documentation
section: content
---

# Tenant Storage {#tenant-storage}

Tenant storage is where tenants' uuids and domains are stored. You can store things like the tenant's plan, subscription information, and tenant-specific application configuration in tenant storage. You can use these functions:
```php
get (string|array $key, string $uuid = null) // $uuid defaults to the current tenant's UUID
put (string|array $key, mixed $value = null, string $uuid = null) // if $key is array, make sure $value is null
```

To put something into the tenant storage, you can use `put()` or `set()`.
```php
tenancy()->put($key, $value);
tenancy()->set($key, $value); // alias for put()
tenancy()->put($key, $value, $uuid);
tenancy()->put(['key1' => 'value1', 'key2' => 'value2']);
tenancy()->put(['key1' => 'value1', 'key2' => 'value2'], null, $uuid);
```

To get something from the storage, you can use `get()`:

```php
tenancy()->get($key);
tenancy()->get($key, $uuid);
tenancy()->get(['key1', 'key2']);
```

> Note: `tenancy()->get(['key1', 'key2'])` returns an array with values only

Note that $key has to be a string or an array with string keys. The value(s) can be of any data type. Example with arrays:

```php
>>> tenant()->put('foo', ['a' => 'b', 'c' => 'd']);
=> [ // put() returns the supplied value(s)
     "a" => "b",
     "c" => "d",
   ]
>>> tenant()->get('foo');
=> [
     "a" => "b",
     "c" => "d",
   ]
```