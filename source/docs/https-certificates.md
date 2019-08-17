---
title: HTTPS Certificates
description: HTTPS Certificates with stancl/tenancy — A Laravel multi-database tenancy package that respects your code..
extends: _layouts.documentation
section: content
---

# HTTPS certificates

HTTPS certificates are very easy to deal with if you use the `yourclient1.yourapp.com`, `yourclient2.yourapp.com` model. You can use a wildcard HTTPS certificate.

If you use the model where second level domains are used, there are multiple ways you can solve this.

This guide focuses on nginx.

### 1. Use nginx with the lua module

Specifically, you're interested in the [`ssl_certificate_by_lua_block`](https://github.com/openresty/lua-nginx-module#ssl_certificate_by_lua_block) directive. Nginx doesn't support using variables such as the hostname in the `ssl_certificate` directive, which is why the lua module is needed.

This approach lets you use one server block for all tenants.

### 2. Add a simple server block for each tenant

You can store most of your config in a file, such as `/etc/nginx/includes/tenant`, and include this file into tenant server blocks.

```nginx
server {
  include includes/tenant;
  server_name foo.bar;
  # ssl_certificate /etc/foo/...;
}
```

### Generating certificates

You can generate a certificate using certbot. If you use the `--nginx` flag, you will need to run certbot as root. If you use the `--webroot` flag, you only need the user that runs it to have write access to the webroot directory (or perhaps webroot/.well-known is enough) and some certbot files (you can specify these using --work-dir, --config-dir and --logs-dir).

Creating this config dynamically from PHP is not easy, but is probably feasible. Giving `www-data` write access to `/etc/nginx/sites-available/tenants.conf` should work.

However, you still need to reload nginx configuration to apply the changes to configuration. This is problematic and I'm not sure if there is a simple and secure way to do this from PHP.