---
title: Tenant Manager
description: Tenant Manager | stancl/tenancy — A Laravel multi-database tenancy package that respects your code.
extends: _layouts.documentation
section: content
---

# Tenant Manager {#tenant-manager}

This page documents a couple of other `TenantManager` methods you may find useful.

### Finding tenant using UUID

`find()` is an alias for `getTenantById()`. You may use the second argument to specify the key(s) as a string/array.

```php
>>> tenant()->find('dbe0b330-1a6e-11e9-b4c3-354da4b4f339');
=> [
     "uuid" => "dbe0b330-1a6e-11e9-b4c3-354da4b4f339",
     "domain" => "localhost",
     "foo" => "bar",
   ]
>>> tenant()->find('dbe0b330-1a6e-11e9-b4c3-354da4b4f339', 'foo');
=> [
     "foo" => "bar",
   ]
>>> tenant()->getTenantById('dbe0b330-1a6e-11e9-b4c3-354da4b4f339', ['foo', 'domain']);
=> [
     "foo" => "bar",
     "domain" => "localhost",
   ]
```

### Getting tenant ID by domain

```php
>>> tenant()->getTenantIdByDomain('localhost');
=> "b3ce3f90-1a88-11e9-a6b0-038c6337ae50"
>>> tenant()->getIdByDomain('localhost');
=> "b3ce3f90-1a88-11e9-a6b0-038c6337ae50"
```

### Finding tenant by domain

You may use the second argument to specify the key(s) as a string/array.

```php
>>> tenant()->findByDomain('localhost');
=> [
     "uuid" => "b3ce3f90-1a88-11e9-a6b0-038c6337ae50",
     "domain" => "localhost",
   ]
```

### Accessing the array

You can access the public array tenant of TenantManager like this:

```php
tenancy()->tenant
```

which is an array. If you want to get the value of a specific key from the array, you can use one of the helpers the key on the tenant array as an argument.

```php
tenant('uuid'); // Does the same thing as tenant()->tenant['uuid']
```

### Getting all tenants

This method returns a collection of arrays.

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

This doesn't delete the tenant's database. If you want to delete it, save the database name prior to deleting the tenant. You can get the database name using `getDatabaseName()`

```php
>>> tenant()->getDatabaseName(tenant()->findByDomain('laravel.localhost'))
=> "tenant67412a60-1c01-11e9-a9e9-f799baa56fd9"
```