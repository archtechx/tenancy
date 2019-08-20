---
title: The Event System
description: The Event System | stancl/tenancy — A Laravel multi-database tenancy package that respects your code..
extends: _layouts.documentation
section: content
---

# The Event System

You can use event hooks to change the behavior of the tenancy boostrapping and tenancy ending processes.

The following events are available:
- `boostrapping`
- `boostrapped`
- `ending`
- `ended`

### Tenant-specific database connection example {#tenant-specific-database-connection-example}

You can hook into these events using `Tenancy::<eventName>`:
```php
\Tenancy::boostrapping(function ($tenantManager) {
    if ($tenantManager->tenant['uuid'] === 'someUUID') {
        config(['database.connections.someDatabaseConnection' => $tenantManager->tenant['databaseConnection']]);
        $tenantManager->database->useConnection('someDatabaseConnection');

        return ['database'];
    }
});
```

The example above checks whether the current tenant has an uuid of `someUUID`. If yes, it creates a new database connection based on data stored in the tenant's storage. Then it changes the default database connection. Finally, it returns an array of the events that this callback prevents.

The following actions can be prevented:
- database connection switch: `database`
- Redis prefix: `redis`
- CacheManager switch: `cache`
- Filesystem changes: `filesystem`

### Tenant-specific configuration example {#tenant-specific-configuration-example}

Another common use case for events is tenant-specific config:
```php
\Tenancy::bootstrapped(function ($tenantManager) {
    config(['some.api.key' => $tenantManager->tenant['api_key']);
});
```