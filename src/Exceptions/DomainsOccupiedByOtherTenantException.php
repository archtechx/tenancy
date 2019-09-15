<?php

declare(strict_types=1);

namespace Stancl\Tenancy\Exceptions;

use Stancl\Tenancy\Contracts\TenantCannotBeCreatedException;

class DomainsOccupiedByOtherTenantException extends TenantCannotBeCreatedException
{
    public function reason(): string
    {
        return "One or more of the tenant's domains are already occupied by another tenant.";
    }
}
