<?php

declare(strict_types=1);

namespace Stancl\Tenancy\Resolvers;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Routing\Route;
use Stancl\Tenancy\Contracts\Tenant;
use Stancl\Tenancy\Exceptions\TenantColumnNotWhitelistedException;
use Stancl\Tenancy\Exceptions\TenantCouldNotBeIdentifiedByPathException;

class PathTenantResolver extends Contracts\CachedTenantResolver
{
    public function resolveWithoutCache(mixed ...$args): Tenant
    {
        /** @var Route $route */
        $route = $args[0];

        /** @var string $key */
        $key = $route->parameter(static::tenantParameterName());
        $column = $route->bindingFieldFor(static::tenantParameterName()) ?? static::tenantModelColumn();

        if ($column !== static::tenantModelColumn() && ! in_array($column, static::allowedExtraModelColumns())) {
            throw new TenantColumnNotWhitelistedException($key);
        }

        if ($key) {
            if ($tenant = tenancy()->find($key, $column, withRelations: true)) {
                /** @var Tenant $tenant */
                return $tenant;
            }
        }

        throw new TenantCouldNotBeIdentifiedByPathException($key);
    }

    public function getPossibleCacheKeys(Tenant&Model $tenant): array
    {
        $columns = array_unique(array_merge(static::allowedExtraModelColumns(), [static::tenantModelColumn()]));
        $columnValuePairs = array_map(fn ($column) => [$column, $tenant->getAttribute($column)], $columns);

        return array_map(fn ($columnValuePair) => $this->formatCacheKey(...$columnValuePair), $columnValuePairs);
    }

    public function resolved(Tenant $tenant, mixed ...$args): void
    {
        /** @var Route $route */
        $route = $args[0];

        // Forget the tenant parameter so that we don't have to accept it in route action methods
        $route->forgetParameter(static::tenantParameterName());
    }

    public function formatCacheKey(mixed ...$args): string
    {
        // When called in resolve(), $args contains the route
        // When called in getPossibleCacheKeys(), $args contains the column-value pair
        if ($args[0] instanceof Route) {
            $column = $args[0]->bindingFieldFor(static::tenantParameterName()) ?? static::tenantModelColumn();
            $value = $args[0]->parameter(static::tenantParameterName());
        } else {
            [$column, $value] = $args;
        }

        return parent::formatCacheKey($column, $value);
    }

    public static function tenantParameterName(): string
    {
        return config('tenancy.identification.resolvers.' . static::class . '.tenant_parameter_name') ?? 'tenant';
    }

    public static function tenantRouteNamePrefix(): string
    {
        return config('tenancy.identification.resolvers.' . static::class . '.tenant_route_name_prefix') ?? 'tenant.';
    }

    public static function tenantModelColumn(): string
    {
        return config('tenancy.identification.resolvers.' . static::class . '.tenant_model_column') ?? tenancy()->model()->getTenantKeyName();
    }

    public static function tenantParameterValue(Tenant $tenant): string
    {
        return $tenant->getAttribute(static::tenantModelColumn());
    }

    /** @return string[] */
    public static function allowedExtraModelColumns(): array
    {
        return config('tenancy.identification.resolvers.' . static::class . '.allowed_extra_model_columns') ?? [];
    }
}
