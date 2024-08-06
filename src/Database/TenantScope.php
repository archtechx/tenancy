<?php

declare(strict_types=1);

namespace Stancl\Tenancy\Database;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;
use Stancl\Tenancy\Tenancy;

class TenantScope implements Scope
{
    /**
     * @param Builder<Model> $builder
     */
    public function apply(Builder $builder, Model $model)
    {
        if (! tenancy()->initialized) {
            return;
        }

        $builder->where($model->qualifyColumn(Tenancy::tenantKeyColumn()), tenant()->getTenantKey());
    }

    /**
     * @param Builder<Model> $builder
     */
    public function extend(Builder $builder): void
    {
        $builder->macro('withoutTenancy', function (Builder $builder) {
            return $builder->withoutGlobalScope(static::class);
        });
    }
}
