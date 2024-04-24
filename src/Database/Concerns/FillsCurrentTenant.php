<?php

declare(strict_types=1);

namespace Stancl\Tenancy\Database\Concerns;

use Stancl\Tenancy\Tenancy;

trait FillsCurrentTenant
{
    public static function bootFillsCurrentTenant(): void
    {
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
