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

        $column = static::tenantModelColumn();

        if ($payload && $tenant = tenancy()->find($payload, $column, withRelations: true)) {
            return $tenant;
        }

        throw new TenantCouldNotBeIdentifiedByRequestDataException($payload);
    }

    public function getPossibleCacheKeys(Tenant&Model $tenant): array
    {
        return [
            $this->formatCacheKey(static::payloadValue($tenant)),
        ];
    }

    public static function payloadValue(Tenant $tenant): string
    {
        return $tenant->getAttribute(static::tenantModelColumn());
    }

    public static function tenantModelColumn(): string
    {
        return config('tenancy.identification.resolvers.' . static::class . '.tenant_model_column') ?? tenancy()->model()->getTenantKeyName();
    }

    /**
     * Returns the name of the header used for identification, or null if header identification is disabled.
     */
    public static function headerName(): string|null
    {
        return config('tenancy.identification.resolvers.' . static::class . '.header');
    }

    /**
     * Returns the name of the query parameter used for identification, or null if query parameter identification is disabled.
     */
    public static function queryParameterName(): string|null
    {
        return config('tenancy.identification.resolvers.' . static::class . '.query_parameter');
    }

    /**
     * Returns the name of the cookie used for identification, or null if cookie identification is disabled.
     */
    public static function cookieName(): string|null
    {
        return config('tenancy.identification.resolvers.' . static::class . '.cookie');
    }
}
