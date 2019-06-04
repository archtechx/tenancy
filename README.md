# Tenancy

[![Laravel 5.7+](https://img.shields.io/badge/laravel-5.7+-red.svg)](https://laravel.com)
[![Latest Stable Version](https://poser.pugx.org/stancl/tenancy/version)](https://packagist.org/packages/stancl/tenancy)
[![Travis CI build](https://travis-ci.com/stancl/tenancy.svg?branch=master)](https://travis-ci.com/stancl/tenancy)
[![codecov](https://codecov.io/gh/stancl/tenancy/branch/master/graph/badge.svg)](https://codecov.io/gh/stancl/tenancy)

### *A Laravel multi-database tenancy implementation that respects your code.*

You won't have to change a thing in your application's code.\*

- :heavy_check_mark: No model traits to change database connection
- :heavy_check_mark: No replacing of Laravel classes (`Cache`, `Storage`, ...) with tenancy-aware classes
- :heavy_check_mark: Built-in tenant identification based on hostname (including second level domains)

\* depending on how you use the filesystem. Be sure to read [that section](#filesystemstorage). Everything else will work out of the box.

## Installation

### Requirements

- Laravel 5.7 or 5.8
- phpredis (predis is not supported)

### Installing the package

```
composer require stancl/tenancy
```

This package follows [semantic versioning 2.0.0](https://semver.org). Each major release will have its own branch, so that bug fixes can be provided for older versions as well.

### Configuring the `InitializeTenancy` middleware

The `TenancyServiceProvider` automatically adds the `tenancy` middleware group which can be assigned to routes. You only need to make sure the middleware is top priority.

Open `app/Http/Kernel.php` and make the middleware top priority, so that it gets executed before anything else, making sure things like the database switch connections soon enough.

```php
protected $middlewarePriority = [
    \Stancl\Tenancy\Middleware\InitializeTenancy::class,
    // ...
];
```

When a tenant route is visited, but the tenant can't be identified, an exception is thrown. If you want to change this behavior, to a redirect for example, add this to your `app/Providers/AppServiceProvider.php`'s `boot()` method.

```php
// use Stancl\Tenancy\Middleware\InitializeTenancy;

$this->app->bind(InitializeTenancy::class, function ($app) {
    return new InitializeTenancy(function ($exception) {
        // redirect
    });
});
```

### Creating tenant routes

`Stancl\Tenancy\TenantRouteServiceProvider` maps tenant routes only if the current domain is not [exempt from tenancy](#exempt_domains). Tenant routes are loaded from `routes/tenant.php`.

Rename the `routes/web.php` file to `routes/tenant.php`. This file will contain routes accessible only with tenancy.

Create an empty `routes/web.php` file. This file will contain routes accessible without tenancy (such as the routes specific to the part of your app which creates tenants - landing page, sign up page, etc).

### Publishing the configuration file

```php
php artisan vendor:publish --provider='Stancl\Tenancy\TenancyServiceProvider' --tag=config
```

You should see something along the lines of `Copied File [...] to [/config/tenancy.php]`.

#### `exempt_domains`

Domains listed in this array won't have tenant routes.

For example, you can put the domain on which you have your landing page here.

#### `database`

Databases will be named like this:

```php
config('tenancy.database.prefix') . $uuid . config('tenancy.database.suffix')
```

They will use a connection based on the connection specified using the `based_on` setting. Using `mysql` or `sqlite` is fine, but if you need to change more things than just the database name, you can create a new `tenant` connection and set `tenancy.database.based_on` to `tenant`.

#### `redis`

Keys will be prefixed with:

```php
config('tenancy.redis.prefix_base') . $uuid
```

These changes will only apply for connections listed in `prefixed_connections`.

#### `cache`

Cache keys will be tagged with a tag:

```php
config('tenancy.cache.tag_base') . $uuid
```

#### `filesystem`

Filesystem paths will be suffixed with:

```php
config('tenancy.filesystem.suffix_base') . $uuid
```

These changes will only apply for disks listed in `disks`.

You can see an example in the [Filesystem](#filesystemstorage) section of the documentation The `filesystem.root_override` section is explained there as well.

# Usage

## Creating a Redis connection for storing tenancy-related data

Add an array like this to `database.redis` config:

```php
'tenancy' => [
    'host' => env('TENANCY_REDIS_HOST', '127.0.0.1'),
    'password' => env('TENANCY_REDIS_PASSWORD', null),
    'port' => env('TENANCY_REDIS_PORT', 6380),
    'database' => env('TENANCY_REDIS_DB', 3),
],
```

Note the different `database` number and the different port.

A different port is used in this example, because if you use Redis for caching, you may want to run one instance with no persistence and another instance with persistence for tenancy-related data. If you want to run only one Redis instance, just make sure you use a different database number to avoid collisions.

Read the [Storage driver](#storage-driver) section for more information.

## Obtaining a `TenantManager` instance

You can use the `tenancy()` and `tenant()` helpers to resolve `Stancl\Tenancy\TenantManager` out of the service container. These two helpers are exactly the same, the only reason there are two is nice syntax. `tenancy()->init()` sounds better than `tenant()->init()` and `tenant()->create()` sounds better than `tenancy()->create()`. **You may also use the `Tenancy` facade.**

### Creating a new tenant

```php
>>> tenant()->create('dev.localhost')
=> [
     "uuid" => "49670df0-1a87-11e9-b7ba-cf5353777957",
     "domain" => "dev.localhost",
   ]
```

When you create a new tenant, you can [migrate](#tenant-migrations) their database like this:

```php
\Artisan::call('tenants:migrate', [
    '--tenants' => [$tenant['uuid']]
]);
```

You can also seed the database in the same way. The only difference is the command name (`tenants:seed`).

### Starting a session as a tenant

This runs `TenantManager::bootstrap()` which switches the DB connection, prefixes Redis, changes filesystem root paths, etc.

```php
tenancy()->init();
// The domain will be autodetected unless specified as an argument
tenancy()->init('dev.localhost');
```

### Getting tenant information based on his UUID

You can use `find()`, which is an alias for `getTenantById()`.
You may use the second argument to specify the key(s) as a string/array.

```php
>>> tenant()->getTenantById('dbe0b330-1a6e-11e9-b4c3-354da4b4f339');
=> [
     "uuid" => "dbe0b330-1a6e-11e9-b4c3-354da4b4f339",
     "domain" => "localhost",
     "foo" => "bar",
   ]
>>> tenant()->getTenantById('dbe0b330-1a6e-11e9-b4c3-354da4b4f339', 'foo');
=> [
     "foo" => "bar",
   ]
>>> tenant()->getTenantById('dbe0b330-1a6e-11e9-b4c3-354da4b4f339', ['foo', 'domain']);
=> [
     "foo" => "bar",
     "domain" => "localhost",
   ]
```

### Getting tenant UUID based on his domain

```php
>>> tenant()->getTenantIdByDomain('localhost');
=> "b3ce3f90-1a88-11e9-a6b0-038c6337ae50"
>>> tenant()->getIdByDomain('localhost');
=> "b3ce3f90-1a88-11e9-a6b0-038c6337ae50"
```

### Getting tenant information based on his domain

You may use the second argument to specify the key(s) as a string/array.

```php
>>> tenant()->findByDomain('localhost');
=> [
     "uuid" => "b3ce3f90-1a88-11e9-a6b0-038c6337ae50",
     "domain" => "localhost",
   ]
```

### Getting current tenant information

You can access the public array `tenant` of `TenantManager` like this:

```php
tenancy()->tenant
```

which returns an array. If you want to get the value of a specific key from the array, you can use one of the helpers with an argument --- the key on the `tenant` array.

```php
tenant('uuid'); // Does the same thing as tenant()->tenant['uuid']
```

### Listing all tenants

```php
>>> tenant()->all();
=> Illuminate\Support\Collection {#2980
     all: [
       [
         "uuid" => "32e20780-1a88-11e9-a051-4b6489a7edac",
         "domain" => "localhost",
       ],
       [
         "uuid" => "49670df0-1a87-11e9-b7ba-cf5353777957",
         "domain" => "dev.localhost",
       ],
     ],
   }
>>> tenant()->all()->pluck('domain');
=> Illuminate\Support\Collection {#2983
     all: [
       "localhost",
       "dev.localhost",
     ],
   }
```

### Deleting a tenant

```php
>>> tenant()->delete('dbe0b330-1a6e-11e9-b4c3-354da4b4f339');
=> true
>>> tenant()->delete(tenant()->getTenantIdByDomain('dev.localhost'));
=> true
>>> tenant()->delete(tenant()->findByDomain('localhost')['uuid']);
=> true
```

Note that deleting a tenant doesn't delete his database. You can do this manually, though. To get the database name of a tenant, you can do use the `TenantManager::getDatabaseName()` method.

```php
>>> tenant()->getDatabaseName(tenant()->findByDomain('laravel.localhost'))
=> "tenant67412a60-1c01-11e9-a9e9-f799baa56fd9"
```

## Storage driver

Currently, only Redis is supported, but you're free to code your own storage driver which follows the `Stancl\Tenancy\Interfaces\StorageDriver` interface. Just point the `tenancy.storage_driver` setting at your driver.

**Note that you need to configure persistence on your Redis instance** if you don't want to lose all information about tenants.

Read the [Redis documentation page on persistence](https://redis.io/topics/persistence). You should definitely use AOF and if you want to be even more protected from data loss, you can use RDB **in conjunction with AOF**.

If your cache driver is Redis and you don't want to use AOF with it, run two Redis instances. Otherwise, just make sure you use a different database (number) for tenancy and for anything else.

### Storing custom data

Along with the tenant and database info, you can store your own data in the storage. This is useful, for example, when you want to store tenant-specific config. You can use:

```php
get (string|array $key, string $uuid = null) // $uuid defaults to the current tenant's UUID
put (string|array $key, mixed $value = null, string $uuid = null) // if $key is array, make sure $value is null
```

```php
tenancy()->get($key);
tenancy()->get($key, $uuid);
tenancy()->get(['key1', 'key2']);
tenancy()->put($key, $value);
tenancy()->set($key, $value); // alias for put()
tenancy()->put($key, $value, $uuid);
tenancy()->put(['key1' => 'value1', 'key2' => 'value2']);
tenancy()->put(['key1' => 'value1', 'key2' => 'value2'], null, $uuid);
```

Note that `$key` has to be a string or an array with string keys. The value(s) can be of any data type. Example with arrays:

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

## Database

The entire application will use a new database connection. The connection will be based on the connection specified in `tenancy.database.based_on`. A database name of `tenancy.database.prefix` + tenant UUID + `tenancy.database.suffix` will be used. You can set the suffix to `.sqlite` if you're using sqlite and want the files to be in the sqlite format and you can leave the suffix empty if you're using MySQL (for example).

## Redis

Connections listed in the `tenancy.redis.prefixed_connections` config array use a prefix based on the `tenancy.redis.prefix_base` and the tenant UUID.

**Note: You *must* use phpredis. Predis doesn't support prefixes.**

## Cache

Both `cache()` and `Cache` will use `Stancl\Tenancy\CacheManager`, which adds a tag (`prefix_base` + tenant UUID) to all methods called on it.


## Filesystem/Storage

Assuming the following tenancy config:

```php
'filesystem' => [
    'suffix_base' => 'tenant',
    // Disks which should be suffixed with the suffix_base + tenant UUID.
    'disks' => [
        'local',
        // 'public',
        // 's3',
    ],
    'root_override' => [
        // Disks whose roots should be overriden after storage_path() is suffixed.
        'local' => '%storage_path%/app/',
        'public' => '%storage_path%/app/public/',
    ],
],
```

1. The `storage_path()` will be suffixed with a directory named `tenant` + the tenant UUID.
2. The `local` disk's root will be `storage_path('app')` (which is equivalen to `storage_path() . '/app/'`).
    By default, all disks' roots are suffixed with `tenant` + the tenant UUID. This works for s3 and similar disks. But for local disks, this results in unwanted behavior. The default root for this disk is `storage_path('app')`:
    
    ```php
    'local' => [
        'driver' => 'local',
        'root' => storage_path('app'),
    ],
    ```
    
    However, this configration file was loaded *before* tenancy was initialized. This means that if we simply suffix this disk's root, we get `/path_to_your_application/storage/app/tenant1e22e620-1cb8-11e9-93b6-8d1b78ac0bcd/`. That's not what we want. We want `/path_to_your_application/storage/tenant1e22e620-1cb8-11e9-93b6-8d1b78ac0bcd/app/`.
    
    This is what the override section of the config is for. `%storage_path%` gets replaced by `storage_path()` *after* tenancy is initialized. **The roots of disks listed in the `root_override` section of the config will be replaced according it. All other disks will be simply suffixed with `tenant` + the tenant UUID.**

```php
>>> Storage::disk('local')->getAdapter()->getPathPrefix()
=> "/var/www/laravel/multitenancy/storage/app/"
>>> tenancy()->init()
=> [
     "uuid" => "dbe0b330-1a6e-11e9-b4c3-354da4b4f339",
     "domain" => "localhost",
   ]
>>> Storage::disk('local')->getAdapter()->getPathPrefix()
=> "/var/www/laravel/multitenancy/storage/tenantdbe0b330-1a6e-11e9-b4c3-354da4b4f339/app/"
```

`storage_path()` will also be suffixed in the same way. Note that this means that each tenant will have their own storage directory.

![The folder structure](https://i.imgur.com/GAXQOnN.png)

If you write to these directories, you will need to create them after you create the tenant. See the docs for [PHP's mkdir](http://php.net/function.mkdir).

Logs will be saved to `storage/logs` regardless of any changes to `storage_path()`.

One thing that you **will** have to change if you use storage similarly to the example on the image is your use of the helper function `asset()` (that is, if you use it).

You need to make this change to your code:

```diff
-  asset("storage/images/products/$product_id.png");
+  tenant_asset("images/products/$product_id.png");
```

Note that all (public) tenant assets have to be in the `app/public/` subdirectory of the tenant's storage directory, as shown in the image above.

This is what the backend of `tenant_asset()` returns:
```php
// TenantAssetsController
return response()->file(storage_path('app/public/' . $path));
```

With default filesystem configuration, these two commands are equivalent:

```php
Storage::disk('public')->put($filename, $data);
Storage::disk('local')->put("public/$filename", $data);
```

## Artisan commands

```
Available commands for the "tenants" namespace:
  tenants:list      List tenants.
  tenants:migrate   Run migrations for tenant(s)
  tenants:rollback  Rollback migrations for tenant(s).
  tenants:seed      Seed tenant database(s).
```

#### `tenants:list`

```
$ artisan tenants:list
Listing all tenants.
[Tenant] uuid: dbe0b330-1a6e-11e9-b4c3-354da4b4f339 @ localhost
[Tenant] uuid: 49670df0-1a87-11e9-b7ba-cf5353777957 @ dev.localhost
```

#### `tenants:migrate`, `tenants:rollback`, `tenants:seed`

- You may specify the tenant UUID(s) using the `--tenants` option.

```
$ artisan tenants:seed --tenants=8075a580-1cb8-11e9-8822-49c5d8f8ff23                                                                                                                    
Tenant: 8075a580-1cb8-11e9-8822-49c5d8f8ff23 (laravel.localhost)
Database seeding completed successfully.
```

### Tenant migrations

Tenant migrations are located in `database/migrations/tenant`, so you should move your tenant migrations there.

## Some tips

- If you create a tenant using the interactive console (`artisan tinker`) and use sqlite, you might need to change the database's permissions and/or ownership (`chmod`/`chown`) so that the web application can access it.

## HTTPS certificates

HTTPS certificates are very easy to deal with if you use the `yourclient1.yourapp.com`, `yourclient2.yourapp.com` model. You can use a wildcard HTTPS certificate.

If you use the model where second level domains are used, there are multiple ways you can solve this.

This guide focuses on nginx.

### 1. Use nginx with the lua module

Specifically, you're interested in the [`ssl_certificate_by_lua_block`](https://github.com/openresty/lua-nginx-module#ssl_certificate_by_lua_block) directive. Nginx doesn't support using variables such as the hostname in the `ssl_certificate` directive, which is why the lua module is needed.

This approach lets you use one server block for all tenants.

### 2. Add a simple server block for each tenant

You can store most of your config in a file, such as `/etc/nginx/includes/tenant`, and include this file into tenant server blocks.

```nginx
server {
  include includes/tenant;
  server_name foo.bar;
  # ssl_certificate /etc/foo/...;
}
```

### Generating certificates

You can generate a certificate using certbot. If you use the `--nginx` flag, you will need to run certbot as root. If you use the `--webroot` flag, you only need the user that runs it to have write access to the webroot directory (or perhaps webroot/.well-known is enough) and some certbot files (you can specify these using --work-dir, --config-dir and --logs-dir).

Creating this config dynamically from PHP is not easy, but is probably feasible. Giving `www-data` write access to `/etc/nginx/sites-available/tenants.conf` should work.

However, you still need to reload nginx configuration to apply the changes to configuration. This is problematic and I'm not sure if there is a simple and secure way to do this from PHP.

## Testing

If you run the tests of this package, please make sure you don't store anything in Redis @ 127.0.0.1:6379 db#14. The contents of this database are flushed everytime the tests are run.

Some tests are run only if the `CI`, `TRAVIS` and `CONTINUOUS_INTEGRATION` environment variables are set to `true`. This is to avoid things like bloating your MySQL instance with test databases.
