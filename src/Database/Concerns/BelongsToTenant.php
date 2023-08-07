<?php

declare(strict_types=1);

namespace Stancl\Tenancy\Database\Concerns;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Stancl\Tenancy\Contracts\Tenant;
use Stancl\Tenancy\Database\TenantScope;
use Stancl\Tenancy\Tenancy;

/**
 * @property-read Tenant $tenant
 */
trait BelongsToTenant
{
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(config('tenancy.models.tenant'), Tenancy::tenantKeyColumn());
    }

    public static function bootBelongsToTenant(): void
    {
        // If 'tenancy.rls.enabled' is true or this model implements RLSModel
        // Scope queries using Postgres RLS instead of TenantScope
        if (! (config('tenancy.rls.enabled') || (new static) instanceof RLSModel)) {
            static::addGlobalScope(new TenantScope);
        }

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
