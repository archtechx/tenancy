<?php

declare(strict_types=1);

namespace Stancl\Tenancy\Database\Concerns;

use Stancl\Tenancy\Contracts\Tenant;
use Stancl\Tenancy\Database\TenantScope;

/**
 * @property-read Tenant $tenant
 */
trait BelongsToTenant
{
    public static $tenantIdColumn = 'tenant_id';

    public function tenant()
    {
        return $this->belongsTo(config('tenancy.tenant_model'), static::$tenantIdColumn);
    }

    public static function bootBelongsToTenant()
    {
        static::addGlobalScope(new TenantScope);

        static::creating(function ($model) {
            if (! $model->getAttribute(static::$tenantIdColumn) && ! $model->relationLoaded('tenant')) {
                if (tenancy()->initialized) {
                    $model->setAttribute(static::$tenantIdColumn, tenant()->getTenantKey());
                    $model->setRelation('tenant', tenant());
                }
            }
        });
    }
}
