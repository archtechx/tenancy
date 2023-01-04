<?php

declare(strict_types=1);

namespace Stancl\Tenancy\Resolvers;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Stancl\Tenancy\Contracts\Domain;
use Stancl\Tenancy\Contracts\Tenant;
use Stancl\Tenancy\Exceptions\TenantCouldNotBeIdentifiedOnDomainException;

class DomainTenantResolver extends Contracts\CachedTenantResolver
{
    /** The model representing the domain that the tenant was identified on. */
    public static Domain $currentDomain; // todo |null?

    public function resolveWithoutCache(mixed ...$args): Tenant
    {
        $domain = $args[0];

        $tenant = config('tenancy.models.tenant')::query()
            ->whereHas('domains', fn (Builder $query) => $query->where('domain', $domain))
            ->with('domains')
            ->first();

        if ($tenant) {
            $this->setCurrentDomain($tenant, $domain);

            return $tenant;
        }

        throw new TenantCouldNotBeIdentifiedOnDomainException($args[0]);
    }

    public function resolved(Tenant $tenant, mixed ...$args): void
    {
        $this->setCurrentDomain($tenant, $args[0]);
    }

    protected function setCurrentDomain(Tenant $tenant, string $domain): void
    {
        /** @var Tenant&Model $tenant */
        static::$currentDomain = $tenant->domains->where('domain', $domain)->first();
    }

    public function getArgsForTenant(Tenant $tenant): array
    {
        /** @var Tenant&Model $tenant */

        $tenant->unsetRelation('domains');

        return $tenant->domains->map(function (Domain&Model $domain) {
            return [$domain->domain];
        })->toArray();
    }
}
