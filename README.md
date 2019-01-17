# Tenancy

### *A Laravel multi-database tenancy implementation that respects your code.*

You won't have to change a thing in your application's code.

- :white_check_mark: No model traits to change database connection
- :white_check_mark: No replacing of Laravel classes (`Cache`, `Storage`, ...) with tenancy-aware classes
- :white_check_mark: Built-in tenant identification based on hostname

## Installation

### Installing the package

```
composer require stancl/tenancy
```

### Adding the `InitializeTenancy` middleware

Open `app/Http/Kernel.php` and make the following changes:

First, you want to create middleware groups so that we can apply this middleware on routes.
- Create a new middleware group in `$middlewareGroups`:
    ```php
    'tenancy' => [
        \Stancl\Tenancy\Middleware\InitializeTenancy::class,
    ],
    ```
- Create a new middleware group in `$routeMiddleware`:
    ```php
    'tenancy' => \Stancl\Tenancy\Middleware\InitializeTenancy::class,
    ```
- Make the middleware top priority, so that it gets executed before anything else, thus making sure things like the database switch connections soon enough.
    ```php
    protected $middlewarePriority = [
        \Stancl\Tenancy\Middleware\InitializeTenancy::class,
    ```

#### Configuring the middleware

When a tenant route is visited, but the tenant can't be identified, an exception can be thrown. If you want to change this behavior, to a redirect for example, add this to your `app/Providers/AppServiceProvider.php`'s `boot()` method.

```php
// use Stancl\Tenancy\Middleware\InitializeTenancy;

$this->app->bind(InitializeTenancy::class, function ($app) {
    return new InitializeTenancy(function ($exception) {
        // redirect
    });
});
```

### Creating tenant routes

Add this method into `app/Providers/RouteServiceProvider.php`:

```php
/**
 * Define the "tenant" routes for the application.
 *
 * These routes all receive session state, CSRF protection, etc.
 *
 * @return void
 */
protected function mapTenantRoutes()
{
    Route::middleware(['web', 'tenancy'])
        ->namespace($this->namespace)
        ->group(base_path('routes/tenant.php'));
}
```

And add this line to `map()`:

```php
$this->mapTenantRoutes();
```

Now rename the `routes/web.php` file to `routes/tenant.php`. This file will contain routes accessible only with tenancy.

Create an empty `routes/web.php` file. This file will contain routes accessible without tenancy (such as the landing page.)

### Publishing the configuration file

```
php artisan vendor:publish --provider='Stancl\Tenancy\TenancyServiceProvider' --tag=config
```

You should see something along the lines of `Copied File [...] to [/config/tenancy.php]`.

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

### `filesystem`

Filesystem paths will be suffixed with:

```php
config('tenancy.filesystem.suffix_base') . $uuid
```

These changes will only apply for disks listen in `disks`.

You can see an example in the [Filesystem](#Filesystem) section of the documentation.

# Usage

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

## Storage driver

Currently, only Redis is supported, but you're free to code your own storage driver which follows the `Stancl\Tenancy\Interfaces\StorageDriver` interface. Just point the `tenancy.storage_driver` setting at your driver.

**Note that you need to configure persistence on your Redis instance** if you don't want to lose all information about tenants.

Read the [Redis documentation page on persistence](https://redis.io/topics/persistence). You should definitely use AOF and if you want to be even more protected from data loss, you can use RDB **in conjunction with AOF**.

If your cache driver is Redis and you don't want to use AOF with it, run two Redis instances. Otherwise, just make sure you use a different database (number) for tenancy and for anything else.

### Storing custom data

Along with the tenant and database info, you can store your own data in the storage. You can use:

```php
tenancy()->get($key);
tenancy()->put($key, $value);
tenancy()->set($key, $value); // alias for put()
```

Note that `$key` has to be a string.

## Database

The entire application will use a new database connection. The connection will be based on the connection specified in `tenancy.database.based_on`. A database name of `tenancy.database.prefix` + tenant UUID + `tenancy.database.suffix` will be used. You can set the suffix to `.sqlite` if you're using sqlite and want the files to be in the sqlite format and you can leave the suffix empty if you're using MySQL (for example).

## Redis

Connections listed in the `tenancy.redis.prefixed_connections` config array use a prefix based on the `tenancy.redis.prefix_base` and the tenant UUID.

**Note: You *must* use phpredis for prefixes to work. Predis doesn't support prefixes.**

## Cache

Both `cache()` and `Cache` will use `Stancl\Tenancy\CacheManager`, which adds a tag (`prefix_base` + tenant UUID) to all methods called on it.


## Filesystem

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

- You may specify the tenant(s) UUIDs using the `--tenants` option.

## Some tips

- If you create a tenant using the interactive console (`artisan tinker`) and use sqlite, you might need to change the database's permissions and/or ownership (`chmod`/`chown`) so that the web application can access it.
