---
title: Miscellaneous Tips
description: Miscellaneous Tips | stancl/tenancy — A Laravel multi-database tenancy package that respects your code..
extends: _layouts.documentation
section: content
---

# Miscellaneous Tips {#misc-tips}

## Tenant Redirect {#tenant-redirect}

A customer has signed up on your website, you have created a new tenant and now you want to redirect the customer to their website. You can use the `tenant()` method on Redirect, like this:

```php
// tenant sign up controller
return redirect()->route('dashboard')->tenant($tenant['domain']);
```

## Custom ID scheme

If you don't want to use UUIDs and want to use something more human-readable (even domain concatenated with uuid, for example), you can create a custom class for this:

```php
use Stancl\Tenancy\Interfaces\UniqueIdentifierGenerator;

class MyUniqueIDGenerator implements UniqueIdentifierGenerator
{
    public static function handle(string $domain, array $data): string
    {
        return $domain . \Webpatser\Uuid\Uuid::generate(1, $domain);
    }
}
```

and then set the `tenancy.unique_id_generator` config to the full path to your class.

