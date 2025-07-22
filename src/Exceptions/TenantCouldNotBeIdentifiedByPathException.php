<?php

declare(strict_types=1);

namespace Stancl\Tenancy\Exceptions;

use Stancl\Tenancy\Contracts\TenantCouldNotBeIdentifiedException;

class TenantCouldNotBeIdentifiedByPathException extends TenantCouldNotBeIdentifiedException
{
    public function __construct(int|string $tenant_id)
    {
        $this->tenantCouldNotBeIdentified("on path with tenant key: $tenant_id");
    }
}
