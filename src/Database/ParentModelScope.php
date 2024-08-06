<?php

declare(strict_types=1);

namespace Stancl\Tenancy\Database;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

class ParentModelScope implements Scope
{
    /**
     * @param Builder<Model> $builder
     */
    public function apply(Builder $builder, Model $model): void
    {
        if (! tenancy()->initialized) {
            return;
        }

        $builder->whereHas($builder->getModel()->getRelationshipToPrimaryModel());
    }

    /**
     * @param Builder<Model> $builder
     */
    public function extend(Builder $builder): void
    {
        $builder->macro('withoutParentModel', function (Builder $builder) {
            return $builder->withoutGlobalScope(static::class);
        });
    }
}
