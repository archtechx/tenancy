<?php

namespace Stancl\Tenancy\Contracts;

use Stancl\Tenancy\TenantManager;

/** Additional features, like Telescope tags and tenant redirects. */
interface Feature
{
    // todo is the tenantManager argument necessary?
    public function bootstrap(TenantManager $tenantManager): void;
}