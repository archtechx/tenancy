---
title: Application Testing
description: Application Testing with stancl/tenancy — A Laravel multi-database tenancy package that respects your code..
extends: _layouts.documentation
section: content
---

# Application Testing {#application-testing}

To test your application with this package installed, you can create tenants in the `setUp()` method of your test case:

```php
protected function setUp(): void
{
    parent::setUp();

    tenant()->create('test.localhost');
    tenancy()->init('test.localhost');
}
```

If you're using the database storage driver, you will also need to run the `create_tenants_table` migration:
```php
protected function setUp(): void
{
    parent::setUp();

    $this->call('migrate', [
        '--path' => database_path('migrations'),
        '--database' => 'sqlite',
    ]);

    tenant()->create('test.localhost');
    tenancy()->init('test.localhost');
}
```

If you're using the Redis storage driver, flush the database in `setUp()`:

```php
protected function setUp(): void
{
    parent::setUp();

    // make sure you're using a different connection for testing to avoid losing data
    Redis::connection('tenancyTesting')->flushdb();

    tenant()->create('test.localhost');
    tenancy()->init('test.localhost');
}
```