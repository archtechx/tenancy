<?php

namespace Stancl\Tenancy\Contracts;

use Stancl\Tenancy\DatabaseConfig;

interface TenantWithDatabase extends Tenant
{
    public function database(): DatabaseConfig;
}
