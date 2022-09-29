<?php

declare(strict_types=1);

namespace Stancl\Tenancy\Database\Concerns;

use Stancl\Tenancy\Database\Contracts\TenantWithDatabase;
use Stancl\Tenancy\Database\DatabaseConfig;

trait HasDatabase
{
    public function database(): DatabaseConfig
    {
        /** @var TenantWithDatabase $this */

        return new DatabaseConfig($this);
    }
}
