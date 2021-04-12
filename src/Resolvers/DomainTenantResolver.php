<?php

declare(strict_types=1);

namespace Stancl\Tenancy\Resolvers;

use Stancl\Tenancy\Contracts\Domain;
use Stancl\Tenancy\Contracts\Tenant;
use Stancl\Tenancy\Exceptions\TenantCouldNotBeIdentifiedOnDomainException;

class DomainTenantResolver extends Contracts\CachedTenantResolver
{
    /**
     * The model representing the domain that the tenant was identified on.
     *
     * @var Domain
     */
    public static $currentDomain;

    /** @var bool */
    public static $shouldCache = false;

    /** @var int */
    public static $cacheTTL = 3600; // seconds

    /** @var string|null */
    public static $cacheStore = null; // default

    public function resolveWithoutCache(...$args): Tenant
    {
        $domain = $args[0];

        /** @var Tenant|null $tenant */
        $tenant = config('tenancy.tenant_model')::query()
            ->whereHas('domains', function ($query) use ($domain) {
                $query->select(['tenant_id', 'domain'])->where('domain', $domain);
            })
            ->with([
                'domains' => function ($query) use ($domain) {
                    $query->where('domain', $domain);
                },
            ])
            ->first();

        if ($tenant) {
            static::$currentDomain = $tenant->domains->first();

            return $tenant;
        }

        throw new TenantCouldNotBeIdentifiedOnDomainException($args[0]);
    }

    public function tenantIdentifiedFromCache(Tenant $tenant, ...$args): void
    {
        static::$currentDomain = $tenant->domains->first();
    }

    public function getArgsForTenant(Tenant $tenant): array
    {
        $tenant->unsetRelation('domains');

        return $tenant->domains->map(function (Domain $domain) {
            return [$domain->domain];
        })->toArray();
    }
}
