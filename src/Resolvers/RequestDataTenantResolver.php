<?php

declare(strict_types=1);

namespace Stancl\Tenancy\Resolvers;

use Stancl\Tenancy\Contracts\Tenant;
use Stancl\Tenancy\Contracts\TenantResolver;
use Stancl\Tenancy\Exceptions\TenantCouldNotBeIdentifiedByRequestDataException;
use Stancl\Tenancy\Repository\TenantRepository;

class RequestDataTenantResolver implements TenantResolver
{
    public function __construct(
        private readonly TenantRepository $tenantRepository,
    ) {
    }

    public function resolve(mixed ...$args): Tenant
    {
        $payload = $args[0];

        if ($payload && $tenant = $this->tenantRepository->find($payload)) {
            return $tenant;
        }

        throw new TenantCouldNotBeIdentifiedByRequestDataException($payload);
    }
}
