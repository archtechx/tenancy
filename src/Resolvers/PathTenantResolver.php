<?php

namespace Stancl\Tenancy\Resolvers;

use Illuminate\Routing\Route;
use Stancl\Tenancy\Contracts\Tenant;
use Stancl\Tenancy\Contracts\TenantResolver;
use Stancl\Tenancy\Exceptions\TenantCouldNotBeIdentifiedByPathException;

class PathTenantResolver implements TenantResolver
{
    public function resolve(...$args): Tenant
    {
        /** @var Route $route */
        $route = $args[0];

        if ($id = $route->parameter('tenant')) {
            $route->forgetParameter('tenant');
            
            if ($tenant = config('tenancy.tenant_model')::find($id)) {
                return $tenant;
            }
        }

        throw new TenantCouldNotBeIdentifiedByPathException($id);
    }
}
