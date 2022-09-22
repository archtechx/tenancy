<?php

declare(strict_types=1);

namespace Stancl\Tenancy\Database;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

class ParentModelScope implements Scope
{
    public function apply(Builder $builder, Model $model): void
    {
        if (! tenancy()->initialized) {
            return;
        }

        $builder->whereHas($builder->getModel()->getRelationshipToPrimaryModel());
    }

    public function extend(Builder $builder)
    {
        $builder->macro('withoutParentModel', function (Builder $builder) {
            return $builder->withoutGlobalScope($this);
        });
    }
}
