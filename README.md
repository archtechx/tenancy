# Tenancy

![Laravel 5.7](https://img.shields.io/badge/laravel-5.7-red.svg)
![Beta - experimental](https://img.shields.io/badge/beta-experimental-yellow.svg)
![Travis CI build](https://travis-ci.com/stancl/tenancy.svg?branch=master)
![codecov](https://codecov.io/gh/stancl/tenancy/branch/master/graph/badge.svg)

### *A Laravel multi-database tenancy implementation that respects your code.*

You won't have to change a thing in your application's code.\*

- :white_check_mark: No model traits to change database connection
- :white_check_mark: No replacing of Laravel classes (`Cache`, `Storage`, ...) with tenancy-aware classes
- :white_check_mark: Built-in tenant identification based on hostname

\* depending on how you use the filesystem. Be sure to read [that section](#filesystemstorage). Everything else will work out of the box.

## Installation

### Installing the package

```
composer require stancl/tenancy
```

### Configuring the `InitializeTenancy` middleware

The `TenancyServiceProvider` automatically adds the `tenancy` middleware group which can be assigned to routes. You only need to make sure the middleware is top priority.

Open `app/Http/Kernel.php` and make the middleware top priority, so that it gets executed before anything else, making sure things like the database switch connections soon enough.

```php
protected $middlewarePriority = [
    \Stancl\Tenancy\Middleware\InitializeTenancy::class,
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

```
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
config('tenancy.cache.prefix_base') . $uuid
```

#### `filesystem`

Filesystem paths will be suffixed with:

```php
config('tenancy.filesystem.suffix_base') . $uuid
```

These changes will only apply for disks listen in `disks`.

You can see an example in the [Filesystem](#filesystemstorage) section of the documentation.

# Usage

## Creating a Redis connection for storing tenancy-related data

Add an array like this to `database.redis` config:

```php
'tenancy' => [
    'host' => env('REDIS_TENANCY_HOST', '127.0.0.1'),
    'password' => env('REDIS_TENANCY_PASSWORD', null),
    'port' => env('REDIS_TENANCY_PORT', 6380),
    'database' => env('REDIS_TENANCY_DB', 3),
],
```

Note the different `database` number and the different port.

A different port is used in this example, because if you use Redis for caching, you may want to run one instance with no persistence and another instance with persistence for tenancy-related data. If you want to run only one Redis instance, just make sure you use a different database number to avoid collisions.

Read the [Storage driver](#storage-driver) section for more information.

## Obtaining a `TenantManager` instance

You can use the `tenancy()` and `tenant()` helpers to resolve `Stancl\Tenancy\TenantManager` out of the service container. These two helpers are exactly the same, the only reason there are two is nice syntax. `tenancy()->init()` sounds better than `tenant()->init()` and `tenant()->create()` sounds better than `tenancy()->create()`.

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

Note that `$key` has to be a string or an array with string keys.

## Database

The entire application will use a new database connection. The connection will be based on the connection specified in `tenancy.database.based_on`. A database name of `tenancy.database.prefix` + tenant UUID + `tenancy.database.suffix` will be used. You can set the suffix to `.sqlite` if you're using sqlite and want the files to be in the sqlite format and you can leave the suffix empty if you're using MySQL (for example).

## Redis

Connections listed in the `tenancy.redis.prefixed_connections` config array use a prefix based on the `tenancy.redis.prefix_base` and the tenant UUID.

**Note: You *must* use phpredis for prefixes to work. Predis doesn't support prefixes.**

## Cache

Both `cache()` and `Cache` will use `Stancl\Tenancy\CacheManager`, which adds a tag (`prefix_base` + tenant UUID) to all methods called on it.


## Filesystem/Storage

Assuming the following tenancy config:

```php
'filesystem' => [
    'suffix_base' => 'tenant',
    // Disks which should be suffixed with the prefix_base + tenant UUID.
    'disks' => [
        'local',
        // 's3',
    ],
],
```

The `local` filesystem driver will be suffixed with a directory containing `tenant` and the tenant UUID.

```php
>>> Storage::disk('local')->getAdapter()->getPathPrefix()
=> "/var/www/laravel/multitenancy/storage/app/"
>>> tenancy()->init()
=> [
     "uuid" => "dbe0b330-1a6e-11e9-b4c3-354da4b4f339",
     "domain" => "localhost",
   ]
>>> Storage::disk('local')->getAdapter()->getPathPrefix()
=> "/var/www/laravel/multitenancy/storage/app/tenantdbe0b330-1a6e-11e9-b4c3-354da4b4f339/"
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
