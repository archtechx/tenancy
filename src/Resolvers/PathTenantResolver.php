<?php

declare(strict_types=1);

namespace Stancl\Tenancy\Resolvers;

use Illuminate\Routing\Route;
use Stancl\Tenancy\Contracts\Tenant;
use Stancl\Tenancy\Contracts\TenantResolver;
use Stancl\Tenancy\Exceptions\TenantCouldNotBeIdentifiedByPathException;
use Stancl\Tenancy\Repository\TenantRepository;

class PathTenantResolver implements TenantResolver
{
    public function __construct(
        private readonly TenantRepository $tenantRepository,
        private readonly string $tenantParameterName = 'tenant',
    ) {
    }

    public function resolve(mixed ...$args): Tenant
    {
        /** @var Route $route */
        [$route] = $args;

        if ($id = $route->parameter($this->tenantParameterName)) {
            $route->forgetParameter($this->tenantParameterName);

            if ($tenant = $this->tenantRepository->find($id)) {
                return $tenant;
            }
        }

        throw new TenantCouldNotBeIdentifiedByPathException($id);
    }
}
