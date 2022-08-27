<?php

declare(strict_types=1);

// todo perhaps create Identification namespace

namespace Stancl\Tenancy\Exceptions;

use Stancl\Tenancy\Contracts\TenantCouldNotBeIdentifiedException;

class TenantCouldNotBeIdentifiedByIdException extends TenantCouldNotBeIdentifiedException
{
    public function __construct(int|string $tenant_id)
    {
        $this
            ->tenantCouldNotBeIdentified("by tenant id: $tenant_id")
            ->title('Tenant could not be identified with that ID')
            ->description('Are you sure the ID is correct and the tenant exists?');
    }
}
