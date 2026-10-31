<?php

declare(strict_types=1);

namespace Stancl\Tenancy\Exceptions;

use Stancl\Tenancy\Contracts\Tenant;
use Stancl\Tenancy\Contracts\TenantCouldNotBeIdentifiedException;

class TenantIsPendingException extends TenantCouldNotBeIdentifiedException
{
    public function __construct(
        public Tenant $tenant,
    ) {
        $this->tenantCouldNotBeIdentified('because the tenant is pending');
    }
}
