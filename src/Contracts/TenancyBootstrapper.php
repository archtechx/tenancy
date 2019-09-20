<?php

declare(strict_types=1);

namespace Stancl\Tenancy\Contracts;

use Stancl\Tenancy\Tenant;

/**
 * TenancyBootstrappers are classes that make existing code tenant-aware.
 */
interface TenancyBootstrapper
{
    public function start(Tenant $tenant);

    public function end();
}
