<?php

declare(strict_types=1);

namespace Stancl\Tenancy\Database\Concerns;

use Stancl\Tenancy\Contracts\Tenant;
use Stancl\Tenancy\Database\TenantScope;
use Stancl\Tenancy\Tenancy;

/**
 * @property-read Tenant $tenant
 */
trait BelongsToTenant
{
    public function tenant()
    {
        return $this->belongsTo(config('tenancy.models.tenant'), Tenancy::tenantKeyColumn());
    }

    public static function bootBelongsToTenant(): void
    {
        static::addGlobalScope(new TenantScope);

        static::creating(function ($model) {
            if (! $model->getAttribute(Tenancy::tenantKeyColumn()) && ! $model->relationLoaded('tenant')) {
                if (tenancy()->initialized) {
                    $model->setAttribute(Tenancy::tenantKeyColumn(), tenant()->getTenantKey());
                    $model->setRelation('tenant', tenant());
                }
            }
        });
    }
}
