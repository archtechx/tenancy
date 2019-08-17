---
title: Writing Storage Drivers
description: Writing Storage Drivers with stancl/tenancy — A Laravel multi-database tenancy package that respects your code..
extends: _layouts.documentation
section: content
---

# Writing Storage Drivers

If you don't want to use the provided DB/Redis storage drivers, you can write your own driver.

To create a driver, create a class that implements the `Stancl\Tenancy\Interfaces\StorageDriver` interface.

For historical reasons, the `TenantManager` will try to json encode/decode data coming from the storage driver. If you want to avoid this, set `public $useJson = false;`. That will make `TenantManager` encode/decode only `put()` and `get()` data, so that data types can be stored correctly.

The DB storage driver has `public $useJson = false;`, while the Redis storage driver doesn't use this property, so it's false by default.

Here's an example:

```php

namespace App\StorageDrivers\MongoDBStorageDriver;

use Stancl\Tenancy\Interfaces\StorageDriver;

class MongoDBStorageDriver implements StorageDriver
{
    public $useJson = false;

    public function identifyTenant(string $domain): array
    {
        //
    }

    public function getAllTenants(array $uuids = []): array
    {
        //
    }

    public function getTenantById(string $uuid, array $fields = []): array
    {
        //
    }

    public function getTenantIdByDomain(string $domain): ?string
    {
        //
    }

    public function createTenant(string $domain, string $uuid): array
    {
        //
    }

    public function deleteTenant(string $uuid): bool
    {
        //
    }

    public function get(string $uuid, string $key)
    {
        //
    }

    public function getMany(string $uuid, array $keys): array
    {
        //
    }

    public function put(string $uuid, string $key, $value)
    {
        //
    }

    public function putMany(string $uuid, array $values): array
    {
        //
    }
}
```