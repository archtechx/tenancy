<?php

namespace Stancl\Tenancy\Contracts;

interface TenantResolver
{
    /**
     * Resolve a tenant using some value.
     *
     * @throws TenantCouldNotBeIdentifiedException
     */
    public function resolve(...$args): Tenant;
}
