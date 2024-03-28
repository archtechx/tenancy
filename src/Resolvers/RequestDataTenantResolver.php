<?php

declare(strict_types=1);

namespace Stancl\Tenancy\Resolvers;

use Illuminate\Database\Eloquent\Model;
use Stancl\Tenancy\Contracts\Tenant;
use Stancl\Tenancy\Exceptions\TenantCouldNotBeIdentifiedByRequestDataException;

class RequestDataTenantResolver extends Contracts\CachedTenantResolver
{
    public static bool $shouldCache = false;

    public static int $cacheTTL = 3600; // seconds

    public static string|null $cacheStore = null; // default

    public function resolveWithoutCache(mixed ...$args): Tenant
    {
        $payload = (string) $args[0];

        if ($payload && $tenant = tenancy()->find($payload)) {
            return $tenant;
        }

        throw new TenantCouldNotBeIdentifiedByRequestDataException($payload);
    }

    public function getPossibleCacheKeys(Tenant&Model $tenant): array
    {
        return [
            $this->formatCacheKey($tenant->getTenantKey()),
        ];
    }
}
