<?php

declare(strict_types=1);

namespace Stancl\Tenancy\Resolvers;

use Stancl\Tenancy\Contracts\Tenant;
use Stancl\Tenancy\Exceptions\TenantCouldNotBeIdentifiedByRequestOriginException;

class RequestOriginTenantResolver extends Contracts\CachedTenantResolver
{
    /** @var bool */
    public static $shouldCache = false;

    /** @var int */
    public static $cacheTTL = 3600; // seconds

    /** @var string|null */
    public static $cacheStore = null; // default

    public function resolveWithoutCache(...$args): Tenant
    {
        $payload = $args[0];

	    $domain = config('tenancy.domain_model')::where('domain', $payload)->first();

	    if ($domain) {
            return $domain->tenant;
        }

        throw new TenantCouldNotBeIdentifiedByRequestOriginException($payload);
    }

    public function getArgsForTenant(Tenant $tenant): array
    {
        return [
            [$tenant->id],
        ];
    }
}
