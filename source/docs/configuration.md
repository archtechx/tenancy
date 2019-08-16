---
title: Configuration
description: Configuring stancl/tenancy — A Laravel multi-database tenancy package that respects your code..
extends: _layouts.documentation
section: content
---

# Configuration {#configuration}

The `config/tenancy.php` file lets you configure how the package behaves.

> **Note:** If the `tenancy.php` file doesn't exist in your `config` directory, you can publish it by running `php artisan vendor:publish --provider='Stancl\Tenancy\TenancyServiceProvider' --tag=config`

### `storage_driver, storage`

This lets you configure the driver for tenant storage, i.e. what will be used to store information about your tenants. You can read more about this on the [Storage Drivers](storage-drivers) page.

Available storage drivers:
- `Stancl\Tenancy\StorageDrivers\RedisStorageDriver`
- `Stancl\Tenancy\StorageDrivers\DatabaseStorageDriver`

### `tenant_route_namespace`

Controller namespace used for routes in `routes/tenant.php`. The default value is the same as the namespace for `web.php` routes.

### `exempt_domains`

If a hostname from this array is visited, the `tenant.php` routes won't be registered, letting you use the same routes as in that file.

### `database`

The application's default connection will be switched to a new one — `tenant`. This connection will be based on the connection specified in `tenancy.database.based_on`. The database name will be `tenancy.database.prefix + tenant UUID + tenancy.database.suffix`.

You can set the suffix to `.sqlite` if you're using sqlite and want the files to be with the `.sqlite` extension. Conversely, you can leave the suffix empty if you're using MySQL, for example.

### `redis`

If `tenancy.redis.tenancy` is set to true, connections listed in `tenancy.redis.prefixed_connections` will be prefixed with `config('tenancy.redis.prefix_base') . $uuid`.

> Note: You need phpredis for multi-tenant Redis.

### `cache`

The `CacheManager` instance that's resolved when you use the `Cache` or the `cache()` helper will be replaced by `Stancl\Tenancy\CacheManager`. This class automatically uses [tags](https://laravel.com/docs/master/cache#cache-tags). The tag will look like `config('tenancy.cache.tag_base') . $uuid`.

If you need to store something in global, non-tenant cache, 

### `filesystem`

> Note: It's important to differentiate(?TODO) storage_path() and the Storage facade. TODO

The `storage_path()` will be suffixed with a directory named `config('tenancy.filesystem.suffix_base') . $uuid`.

The root of each disk listed in `tenancy.filesystem.disks` will be suffixed with `config('tenancy.filesystem.suffix_base') . $uuid`.

**However, this alone would cause unwanted behavior.** It would work for S3 and similar disks, but for local disks, this would result in `/path_to_your_application/storage/app/tenant1e22e620-1cb8-11e9-93b6-8d1b78ac0bcd/`. That's not what we want. We want `/path_to_your_application/storage/tenant1e22e620-1cb8-11e9-93b6-8d1b78ac0bcd/app/`.

That's what the `root_override` section is for. `%storage_path%` gets replaced by `storage_path()` *after* tenancy has been initialized. The roots of disks listed in the `root_override` section of the config will be replaced accordingly. All other disks will be simply suffixed with `tenancy.filesystem.suffix_base` + the tenant UUID.

TODO