---
title: Installation
description: Installing stancl/tenancy — A Laravel multi-database tenancy package that respects your code..
extends: _layouts.documentation
section: content
---

# Installation {#getting-started}

Laravel 5.8 or higher is needed.

### Require the package via composer

First you need to require the package using composer:

```
composer require stancl/tenancy
```

### Automatic installation {#automatic-installation}

To install the package, simply run

```
php artisan tenancy:install
```

You will be asked if you want to store your data in Redis or a relational database. You can read more about this on the [Storage Drivers](/docs/storage-drivers) page.

This will do all the steps listed in the [Manual installation](#manual-installation) section for you.

The only thing you have to do now is create a database/Redis connection. Read the [Storage Drivers](/docs/storage-drivers) page for information about that.

### Manual installation {#manual-installation}

If you prefer installing the package manually, you can do that too. It shouldn't take more than a minute either way.

#### Setting up middleware

Now open `app/Http/Kernel.php` and make the `InitializeTenancy` middleware top priority, so that it gets executed before anything else, making sure things like the database switch connections soon enough:

```php
protected $middlewarePriority = [
    \Stancl\Tenancy\Middleware\InitializeTenancy::class,
    // ...
];
```

#### Creating routes

The package lets you have tenant routes and "exempt" routes. Tenant routes are your application's routes. Exempt routes are routes exempt from tenancy — landing pages, sign up forms, and routes for managing tenants.

Routes in `routes/web.php` are exempt, whereas routes in `routes/tenant.php` have the `InitializeTenancy` middleware automatically applied on them.

So, to create tenant routes, put those routes in a new file called `routes/tenant.php`.

#### Configuration

Run the following:

```
php artisan vendor:publish --provider='Stancl\Tenancy\TenancyServiceProvider' --tag=config
```

This creates a `config/tenancy.php`. You can use it to configure how the package works.

Configuration is explained in detail on the [Configuration](/docs/configuration) page.