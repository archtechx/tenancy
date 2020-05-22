<?php

declare(strict_types=1);

namespace Stancl\Tenancy\Resolvers;

use Stancl\Tenancy\Contracts\Tenant;
use Stancl\Tenancy\Contracts\TenantResolver;
use Stancl\Tenancy\Exceptions\TenantCouldNotBeIdentifiedByRequestDataException;

class RequestDataTenantResolver implements TenantResolver
{
    public function resolve(...$args): Tenant
    {
        $payload = $args[0];

        if ($payload && $tenant = tenancy()->find($payload)) {
            return $tenant;
        }

        throw new TenantCouldNotBeIdentifiedByRequestDataException($payload);
    }
}
