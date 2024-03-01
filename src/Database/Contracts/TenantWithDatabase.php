<?php

declare(strict_types=1);

namespace Stancl\Tenancy\Database\Contracts;

use Stancl\Tenancy\Contracts\Tenant;
use Stancl\Tenancy\Database\DatabaseConfig;

interface TenantWithDatabase extends Tenant
{
    /** Get the tenant's database config. */
    public function database(): DatabaseConfig;
}
