<?php

declare(strict_types=1);

namespace Stancl\Tenancy\Contracts;

use Stancl\Tenancy\TenantManager;

/** Additional features, like Telescope tags and tenant redirects. */
// todo should this be FeatureProvider?
interface Feature
{
    public function bootstrap(TenantManager $tenantManager): void;
}
