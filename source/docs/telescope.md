---
title: Telescope Integration
description: Telescope Integration with stancl/tenancy — A Laravel multi-database tenancy package that respects your code..
extends: _layouts.documentation
section: content
---

# Telescope Integration

Requests in Telescope are automatically tagged with the tenant uuid and domain:

![Telescope Request with tags](https://i.imgur.com/CEEluYj.png)

This lets you filter requests by uuid and domain:

![Filtering by uuid](https://i.imgur.com/SvbOa7S.png)
![Filtering by domain](https://i.imgur.com/dCJuEr1.png)

If you'd like to set Telescope tags in your own code, e.g. in your `AppServiceProvider`, replace your `Telescope::tag()` call like this:
```php
\Tenancy::integrationEvent('telescope', function ($entry) {
    return ['abc']; // your logic
});
```
![Tenancy tags merged with tag abc](https://i.imgur.com/4p1wOiM.png)

Once Telescope 3 is released, you won't have to do this.

To have Telescope working, make sure your `telescope.storage.database.connection` points to a non-tenant connection. It's that way by default, so for most projects, Telescope should work out of the box.