<?php

declare(strict_types=1);

namespace Stancl\Tenancy\Resolvers;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Stancl\Tenancy\Contracts\Domain;
use Stancl\Tenancy\Contracts\SingleDomainTenant;
use Stancl\Tenancy\Contracts\Tenant;
use Stancl\Tenancy\Exceptions\TenantCouldNotBeIdentifiedOnDomainException;

class DomainTenantResolver extends Contracts\CachedTenantResolver
{
    /** The model representing the domain that the tenant was identified on. */
    public static Domain|null $currentDomain = null;

    public function resolveWithoutCache(mixed ...$args): Tenant
    {
        $domain = $args[0];

        /** @var Tenant&Model $tenantModel */
        $tenantModel = config('tenancy.models.tenant')::make();

        if ($tenantModel instanceof SingleDomainTenant) {
            $tenant = $tenantModel->newQuery()
                ->firstWhere('domain', $domain);
        } else {
            $tenant = $tenantModel->newQuery()
                ->whereHas('domains', fn (Builder $query) => $query->where('domain', $domain))
                ->with('domains')
                ->first();
        }

        /** @var (Tenant&Model)|null $tenant */
        if ($tenant) {
            $this->setCurrentDomain($tenant, $domain);

            return $tenant;
        }

        throw new TenantCouldNotBeIdentifiedOnDomainException($domain);
    }

    public function resolved(Tenant $tenant, mixed ...$args): void
    {
        $this->setCurrentDomain($tenant, $args[0]);
    }

    protected function setCurrentDomain(Tenant $tenant, string $domain): void
    {
        /** @var Tenant&Model $tenant */
        if (! $tenant instanceof SingleDomainTenant) {
            static::$currentDomain = $tenant->domains->where('domain', $domain)->first();
        }
    }

    public function getPossibleCacheKeys(Tenant&Model $tenant): array
    {
        if ($tenant instanceof SingleDomainTenant) {
            $domains = array_filter([
                $tenant->getOriginal('domain'), // Previous domain
                $tenant->domain, // Current domain
            ]);
        } else {
            /** @var Tenant&Model $tenant */
            $tenant->unsetRelation('domains');

            $domains = $tenant->domains->map(function (Domain&Model $domain) {
                return $domain->domain;
            })->toArray();
        }

        return array_map(fn (string $domain) => $this->formatCacheKey($domain), $domains);
    }
}
