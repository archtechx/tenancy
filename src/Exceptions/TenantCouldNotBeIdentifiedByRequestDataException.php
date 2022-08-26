<?php

declare(strict_types=1);

namespace Stancl\Tenancy\Exceptions;

use Stancl\Tenancy\Contracts\TenantCouldNotBeIdentifiedException;

class TenantCouldNotBeIdentifiedByRequestDataException extends TenantCouldNotBeIdentifiedException
{
    public function __construct(mixed $payload)
    {
        $this
            ->tenantCouldNotBeIdentified("by request data with payload: $payload")
            ->title('Tenant could not be identified using this request data')
            ->description('Did you forget to create a tenant with this id?');
    }
}
