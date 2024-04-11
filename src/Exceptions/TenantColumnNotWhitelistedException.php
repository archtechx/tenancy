<?php

declare(strict_types=1);

namespace Stancl\Tenancy\Exceptions;

use Stancl\Tenancy\Contracts\TenantCouldNotBeIdentifiedException;

class TenantColumnNotWhitelistedException extends TenantCouldNotBeIdentifiedException
{
    public function __construct(int|string $tenant_id)
    {
        $this
            ->tenantCouldNotBeIdentified("on path with tenant key: $tenant_id (column not whitelisted)")
            ->title('Tenant could not be identified on this route because the used column is not whitelisted.')
            ->description('Please add the column to the list of allowed columns in the PathTenantResolver config.');
    }
}