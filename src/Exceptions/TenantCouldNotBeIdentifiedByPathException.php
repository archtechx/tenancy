<?php

declare(strict_types=1);

namespace Stancl\Tenancy\Exceptions;

use Stancl\Tenancy\Contracts\TenantCouldNotBeIdentifiedException;

class TenantCouldNotBeIdentifiedByPathException extends TenantCouldNotBeIdentifiedException
{
    public function __construct(int|string $tenant_id)
    {
        $this
            ->tenantCouldNotBeIdentified("on path with tenant id: $tenant_id")
            ->title('Tenant could not be identified on this path')
            ->description('Did you forget to create a tenant for this path?');
    }
}
