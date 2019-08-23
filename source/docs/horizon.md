---
title: Horizon Integration
description: Horizon Integration with stancl/tenancy — A Laravel multi-database tenancy package that respects your code..
extends: _layouts.documentation
section: content
---

# Horizon Integration

> Make sure your queue is [correctly configured](/docs/jobs-queues) before using Horizon.

Jobs are automatically tagged with the tenant's uuid and domain:

![UUID and domain tags](https://i.imgur.com/K2oWTJc.png)

You can use these tags to monitor specific tenants' jobs:

![Monitoring tags](https://i.imgur.com/qB6veK7.png)
