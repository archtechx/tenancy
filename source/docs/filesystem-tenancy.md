---
title: Filesystem Tenancy
description: Filesystem Tenancy with stancl/tenancy — A Laravel multi-database tenancy package that respects your code..
extends: _layouts.documentation
section: content
---

# Filesystem Tenancy {#filesystem-tenancy}

> Note: It's important to differentiate between storage_path() and the Storage facade. The Storage facade is what you use to put files into storage, i.e. `Storage::disk('local')->put()`.  `storage_path()` is used to get the path to the storage directory.

The `storage_path()` will be suffixed with a directory named `config('tenancy.filesystem.suffix_base') . $uuid`.

The root of each disk listed in `tenancy.filesystem.disks` will be suffixed with `config('tenancy.filesystem.suffix_base') . $uuid`.

**However, this alone would cause unwanted behavior.** It would work for S3 and similar disks, but for local disks, this would result in `/path_to_your_application/storage/app/tenant1e22e620-1cb8-11e9-93b6-8d1b78ac0bcd/`. That's not what we want. We want `/path_to_your_application/storage/tenant1e22e620-1cb8-11e9-93b6-8d1b78ac0bcd/app/`.

That's what the `root_override` section is for. `%storage_path%` gets replaced by `storage_path()` *after* tenancy has been initialized. The roots of disks listed in the `root_override` section of the config will be replaced accordingly. All other disks will be simply suffixed with `tenancy.filesystem.suffix_base` + the tenant UUID.

Since `storage_path()` will be suffixed, your folder structure will look like this:

![The folder structure](https://i.imgur.com/GAXQOnN.png)

If you write to these directories, you will need to create them after you create the tenant. See the docs for [PHP's mkdir](http://php.net/function.mkdir).

Logs will be saved to `storage/logs` regardless of any changes to `storage_path()`.

One thing that you **will** have to change if you use storage similarly to the example on the image is your use of the helper function `asset()` (that is, if you use it).

You need to make this change to your code:

```diff
-  asset("storage/images/products/$product_id.png");
+  tenant_asset("images/products/$product_id.png");
```

Note that all (public) tenant assets have to be in the `app/public/` subdirectory of the tenant's storage directory, as shown in the image above.

This is what the backend of `tenant_asset()` returns:
```php
// TenantAssetsController
return response()->file(storage_path('app/public/' . $path));
```

With default filesystem configuration, these two commands are equivalent:

```php
Storage::disk('public')->put($filename, $data);
Storage::disk('local')->put("public/$filename", $data);
```

If you want to store something globally, simply create a new disk and *don't* add it to the `tenancy.filesystem.disks` config.