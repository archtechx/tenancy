---
title: Integrations
description: Integrating stancl/tenancy — A Laravel multi-database tenancy package that respects your code..
extends: _layouts.documentation
section: content
---

# Integrations {#integrations}

This package naturally integrates well with Laravel packages, since it does not rely on you explicitly specifying database connections.

There are some exceptions, though. [Telescope integration](/docs/telescope), for example, requires you to change the database connection in `config/telescope.php` to a non-default one, because the default connection is switched to the tenant connection. Some packages should use a central connection for data storage.
