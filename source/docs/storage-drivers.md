---
title: Storage Drivers
description: Storage Drivers of stancl/tenancy — A Laravel multi-database tenancy package that respects your code..
extends: _layouts.documentation
section: content
---

# Storage Drivers {#storage-drivers}

Storage drivers are used to store a list of all tenants, their domains and any extra information you store about your tenants (e.g. their plan).

Currently, database and Redis storage drivers are available as part of the package. However, you can [write your own](/docs/writing-storage-drivers) (and contribute ❤️) storage drivers.

## Database {#database}

The database storage driver lets you store tenant information in a relational database like MySQL, PostgreSQL and SQLite.

The benefit of this storage driver is that you don't have to use both Redis and a database for your data. Also you don't have to do as much configuration.

To use this driver, you need to have a `tenants` table. You may also use a custom database connection. By default, `tenancy.storage.db.connection` is set to `central`, which means that the `central` database connection will be used to store tenants. This connection is not automatically created, so you'd have to create it manually. You can create database connections in the `config/database.php` file.

If you'd like to use an existing connection, you can set this config to the name of the connection, e.g. `mysql`.

To create the `tenants` table, you can use the migration that comes with this package. If you haven't published it during installation, publish it now:
```
php artisan vendor:publish --provider='Stancl\Tenancy\TenancyServiceProvider' --tag=migrations
```

By default, all of your data will be stored in the JSON column `data`. If you want to store some data in a dedicated column (to leverage indexing, for example), add the column to the migration and to `tenancy.custom_columns` config.

Finally, run the migration:
```
php artisan migrate
```

> If you use a non-default connection, such as `central`, you have to specify which DB to migrate using the `--database` option.
> 
> If you have existing migrations related to your app in `database/migrations`, move them to `database/migrations/tenant`. You can read more about tenant migrations [here](/docs/console-commands/#migrate).

## Redis {#redis}

The Redis storage driver lets you store tenant information in Redis, a high-performance key-value store.

The benefit of this storage driver is its performance.

**Note that you need to configure persistence on your Redis instance if you don't want to lose all information about tenants.**

Read the [Redis documentation page on persistence](https://redis.io/topics/persistence). You should definitely use AOF and if you want to be even more protected from data loss, you can use RDB **in conjunction with AOF**.

If your cache driver is Redis and you don't want to use AOF with it, run two Redis instances. Otherwise, just make sure you use a different database (number) for tenancy and for anything else.

To use this driver, create a new Redis connection in the `database.redis` configuration (`config/database.php`) called `tenancy`.

```php
'tenancy' => [
    'host' => env('TENANCY_REDIS_HOST', '127.0.0.1'),
    'password' => env('TENANCY_REDIS_PASSWORD', null),
    'port' => env('TENANCY_REDIS_PORT', 6380), // different port = separate Redis instance
    'database' => env('TENANCY_REDIS_DB', 3), // alternatively, different database number
],
```
