---
title: Jobs & Queues
description: Jobs & Queues with stancl/tenancy — A Laravel multi-database tenancy package that respects your code..
extends: _layouts.documentation
section: content
---

# Jobs & Queues {#jobs-queues}

Jobs are automatically multi-tenant, which means that if a job is dispatched while tenant A is initialized, the job will operate with tenant A's database, cache, filesystem, and Redis.

**However**, if you're using the `database` or `redis` queue driver, you have to make a small tweak to your queue configuration.

Open `config/queue.php` and make sure your queue driver has an explicitly set connection. Otherwise it would use the default one, which would cause issues, since `database.default` is changed by the package and Redis connections are prefixed.

**If you're using `database`, add a new line to `queue.connections.database`:**
```php
'connection' => 'mysql',
```

where `'mysql'` is the name of your non-tenant database connection with a `jobs` table.

**If you're using Redis, make sure its `'connection'` is not in `tenancy.redis.prefixed_connections`.**