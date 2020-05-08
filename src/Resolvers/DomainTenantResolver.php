<?php

namespace Stancl\Tenancy\Resolvers;

use Stancl\Tenancy\Contracts\Tenant;
use Stancl\Tenancy\Contracts\TenantResolver;
use Stancl\Tenancy\Database\Models\Domain;
use Stancl\Tenancy\Exceptions\TenantCouldNotBeIdentifiedOnDomainException;

class DomainTenantResolver implements TenantResolver
{
    public function resolve(...$args): Tenant
    {
        $domain = app(config('tenancy.domain_model'))->where('domain', $args[0])->first();

        if ($domain) {
            return $domain->tenant;
        }

        throw new TenantCouldNotBeIdentifiedOnDomainException($domain);
    }
}