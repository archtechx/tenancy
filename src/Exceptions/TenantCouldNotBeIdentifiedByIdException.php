<?php

declare(strict_types=1);

namespace Stancl\Tenancy\Exceptions;

use Stancl\Tenancy\Contracts\TenantCouldNotBeIdentifiedException;

class TenantCouldNotBeIdentifiedByIdException extends TenantCouldNotBeIdentifiedException
{
    public function __construct(int|string $tenant_id)
    {
        $this
            ->tenantCouldNotBeIdentified("by tenant key: $tenant_id")
            ->title('Tenant could not be identified with that key')
            ->description('Are you sure the key is correct and the tenant exists?');
    }
}
