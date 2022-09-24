<?php

declare(strict_types=1);

namespace Stancl\Tenancy\Resolvers;

use Stancl\Tenancy\Contracts\Tenant;
use Stancl\Tenancy\Contracts\TenantResolver;
use Stancl\Tenancy\Exceptions\TenantCouldNotBeIdentifiedOnDomainException;
use Stancl\Tenancy\Repository\TenantRepository;

class DomainTenantResolver implements TenantResolver
{
    public function __construct(
        private readonly TenantRepository $tenantRepository,
    ) {
    }

    public function resolve(mixed ...$args): Tenant
    {
        $domain = $args[0];

        $tenant = $this->tenantRepository->findForDomain($domain);

        return $tenant ?? throw new TenantCouldNotBeIdentifiedOnDomainException($args[0]);
    }
}
