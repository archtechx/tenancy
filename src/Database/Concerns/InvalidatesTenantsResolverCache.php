<?php

declare(strict_types=1);

namespace Stancl\Tenancy\Database\Concerns;

use Illuminate\Database\Eloquent\Model;
use Stancl\Tenancy\Tenancy;

/**
 * Meant to be used on models that belong to tenants.
 */
trait InvalidatesTenantsResolverCache
{
    public static function bootInvalidatesTenantsResolverCache(): void
    {
        $invalidateCache = static fn (Model $model) => Tenancy::invalidateResolverCache($model->tenant);

        static::saved($invalidateCache);
        static::deleting($invalidateCache);
    }
}
