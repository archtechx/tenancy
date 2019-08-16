---
title: Custom Database Names
description: Custom Database Names with stancl/tenancy — A Laravel multi-database tenancy package that respects your code..
extends: _layouts.documentation
section: content
---

# Custom Database Names {#custom-database-names}

If you want to specify the tenant's database name, set the `tenancy.database_name_key` configuration key to the name of the key that is used to specify the database name in the tenant storage. You must use a name that you won't use for storing other data, so it's recommended to avoid names like `database` and use names like `_stancl_tenancy_database_name` instead. Then just give the key a value during the tenant creation process:

```php
>>> tenant()->create('example.com', [
    '_stancl_tenancy_database_name' => 'example_com'
])
=> [
     "uuid" => "49670df0-1a87-11e9-b7ba-cf5353777957",
     "domain" => "example.com",
     "_stancl_tenancy_database_name" => "example_com",
   ]
```