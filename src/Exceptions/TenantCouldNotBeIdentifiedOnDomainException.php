<?php

declare(strict_types=1);

namespace Stancl\Tenancy\Exceptions;

use Stancl\Tenancy\Contracts\TenantCouldNotBeIdentifiedException;

class TenantCouldNotBeIdentifiedOnDomainException extends TenantCouldNotBeIdentifiedException
{
    public function __construct(string $domain)
    {
        $this
            ->tenantCouldNotBeIdentified("on domain $domain")
            ->title('Tenant could not be identified on this domain')
            ->description('Did you forget to create a tenant for this domain?');
    }
}
