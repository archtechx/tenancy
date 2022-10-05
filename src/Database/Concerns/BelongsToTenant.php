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
    public function tenant()
    {
        return $this->belongsTo(config('tenancy.tenant_model'), static::tenantIdColumn());
    }

    public static function tenantIdColumn(): string
    {
        return config('tenancy.single_db.tenant_id_column');
    }

    public static function bootBelongsToTenant(): void
    {
        static::addGlobalScope(new TenantScope);

        static::creating(function ($model) {
            if (! $model->getAttribute(static::tenantIdColumn()) && ! $model->relationLoaded('tenant')) {
                if (tenancy()->initialized) {
                    $model->setAttribute(static::tenantIdColumn(), tenant()->getTenantKey());
                    $model->setRelation('tenant', tenant());
                }
            }
        });
    }
}
