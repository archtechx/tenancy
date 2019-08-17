---
title: Difference Between This Package And Others
description: Difference Between This Package And Others | with stancl/tenancy — A Laravel multi-database tenancy package that respects your code.
extends: _layouts.documentation
section: content
---

# Difference Between This Package And Others

A frequently asked question is the difference between this package and [tenancy/multi-tenant](https://github.com/tenancy/multi-tenant).

Packages like tenancy/multi-tenant and tenancy/tenancy give you an API for making your application multi-tenant. They give you a tenant DB connection, traits to apply on your models, a guide on creating your own tenant-aware cache, etc.

This package makes your application multi-tenant automatically and attempts to make you not have to change (m)any things in your code.

## Which one should you use?

Depends on what you prefer.

If you want full control and make your application multi-tenant yourself, use tenancy/multi-tenant.

If you want to focus on writing your application instead of tenancy implementations, use stancl/tenancy.
