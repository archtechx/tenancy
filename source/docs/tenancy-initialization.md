---
title: Tenancy Initialization
description: Tenancy Initialization with stancl/tenancy — A Laravel multi-database tenancy package that respects your code..
extends: _layouts.documentation
section: content
---

# Tenancy Initialization {#tenancy-initialization}

Tenancy can be initialized by calling `tenancy()->init()`. The `InitializeTenancy` middleware calls this method automatically.

You can end a tenancy session using `tenancy()->end()`. This is useful if you need to run multiple tenant sessions or a mixed tenant/non-tenant session in a single request/command.

The `tenancy()->init()` method calls `bootstrap()`.

This method switches database connection, Redis connection (if Redis tenancy is enabled), cache and filesystem root paths.

This page goes through the code that actually makes this happen. You don't have to read this page to use the package, but it will give you insight into the magic that's happening in the background, so that you can be more confident in it.

## Database tenancy {#database-tenancy}

`bootstrap()` runs the following method:

```php
public function switchDatabaseConnection()
{
    $this->database->connect($this->getDatabaseName());
}
```

If `tenancy.database_name_key` is set and present in the current tenant's data, the `getDatabaseName()` returns the stored database_name. Otherwise it returns the prefix + uuid + suffix.

```php
public function getDatabaseName($tenant = []): string
{
    $tenant = $tenant ?: $this->tenant;
    if ($key = $this->app['config']['tenancy.database_name_key']) {
        if (isset($tenant[$key])) {
            return $tenant[$key];
        }
    }
    return $this->app['config']['tenancy.database.prefix'] . $tenant['uuid'] . $this->app['config']['tenancy.database.suffix'];
}
```

This is passed as an argument to the `connect()` method. This method creates a new database connection and sets it as the default one.
```php
public function connect(string $database)
{
    $this->createTenantConnection($database);
    $this->useConnection('tenant');
}

public function createTenantConnection(string $database_name)
{
    // Create the `tenant` database connection.
    $based_on = config('tenancy.database.based_on') ?: config('database.default');
    config()->set([
        'database.connections.tenant' => config('database.connections.' . $based_on),
    ]);
    // Change DB name
    $database_name = $this->getDriver() === 'sqlite' ? database_path($database_name) : $database_name;
    config()->set(['database.connections.tenant.database' => $database_name]);
}

public function useConnection(string $connection)
{
    // $this->database = Illuminate\Database\DatabaseManager
    $this->database->setDefaultConnection($connection);
    $this->database->reconnect($connection);
}
```

## Redis tenancy {#redis-tenancy}

The `bootstrap()` method calls `setPhpRedisPrefix()` if `tenancy.redis.tenancy` is `true`.

This method cycles through the `tenancy.redis.prefixed_connections` and sets their prefix to `tenancy.redis.prefix_base` + uuid.
```php
public function setPhpRedisPrefix($connections = ['default'])
{
    // [...]
    foreach ($connections as $connection) {
        $prefix = $this->app['config']['tenancy.redis.prefix_base'] . $this->tenant['uuid'];
        $client = Redis::connection($connection)->client();
        try {
            // [...]
            $client->setOption($client::OPT_PREFIX, $prefix);
        } catch (\Throwable $t) {
            throw new PhpRedisNotInstalledException();
        }
    }
}
```

## Cache tenancy {#cache-tenancy}

`bootstrap()` calls `tagCache()` which replaces the `'cache'` key in the service container with a different `CacheManager`.
```php
public function tagCache()
{
    // [...]
    $this->app->extend('cache', function () {
        return new \Stancl\Tenancy\CacheManager($this->app);
    });
}
```

This `CacheManager` forwards all calls to the inner store, but also adds tag which "scope" the cache and allow for selective cache clearing:
```php
class CacheManager extends BaseCacheManager
{
    public function __call($method, $parameters)
    {
        $tags = [config('tenancy.cache.tag_base') . tenant('uuid')];
        if ($method === 'tags') {
            if (\count($parameters) !== 1) {
                throw new \Exception("Method tags() takes exactly 1 argument. {count($parameters)} passed.");
            }
            $names = $parameters[0];
            $names = (array) $names; // cache()->tags('foo') https://laravel.com/docs/5.7/cache#removing-tagged-cache-items
            return $this->store()->tags(\array_merge($tags, $names));
        }
        return $this->store()->tags($tags)->$method(...$parameters);
    }
}
```

## Filesystem tenancy {#filesystem-tenancy}

`bootstrap()` calls `suffiexFilesystemRootPaths()`. This method changes `storage_path()` and the roots of disks listed in `config('tenancy.filesystem.disks)`. You can read more about this on the [Filesystem Tenancy](/docs/filesystem-tenancy) page.

```php
public function suffixFilesystemRootPaths()
{
    // [...]
    $suffix = $this->app['config']['tenancy.filesystem.suffix_base'] . tenant('uuid');
    // storage_path()
    $this->app->useStoragePath($old['path'] . "/{$suffix}");
    // Storage facade
    foreach ($this->app['config']['tenancy.filesystem.disks'] as $disk) {
        // [...]
        if ($root = \str_replace('%storage_path%', storage_path(), $this->app['config']["tenancy.filesystem.root_override.{$disk}"])) {
            Storage::disk($disk)->getAdapter()->setPathPrefix($root);
        } else {
            $root = $this->app['config']["filesystems.disks.{$disk}.root"];
            Storage::disk($disk)->getAdapter()->setPathPrefix($root . "/{$suffix}");
        }
    }
    // [...]
}
    ```