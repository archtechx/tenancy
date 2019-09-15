<?php

declare(strict_types=1);

namespace Stancl\Tenancy\Contracts;

use Stancl\Tenancy\TenantManager;

/** Additional features, like Telescope tags and tenant redirects. */
interface Feature
{
    public function bootstrap(TenantManager $tenantManager): void;
}
