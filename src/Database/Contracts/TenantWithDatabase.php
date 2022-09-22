<?php

declare(strict_types=1);

namespace Stancl\Tenancy\Database\Contracts;

use Stancl\Tenancy\Contracts\Tenant;
use Stancl\Tenancy\Database\DatabaseConfig;

interface TenantWithDatabase extends Tenant
{
    public function database(): DatabaseConfig;
}
