<?php

declare(strict_types=1);

namespace Stancl\Tenancy\Database\Concerns;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

class PendingScope implements Scope
{
    /**
     * Apply the scope to a given Eloquent query builder.
     *
     * @param Builder<Model> $builder
     *
     * @return void
     */
    public function apply(Builder $builder, Model $model)
    {
        $builder->when(! config('tenancy.pending.include_in_queries'), function (Builder $builder) {
            $builder->whereNull($builder->getModel()->getColumnForQuery('pending_since'));
        });
    }

    /**
     * Add methods to the query builder.
     *
     * @param Builder<\Stancl\Tenancy\Contracts\Tenant&Model> $builder
     */
    public function extend(Builder $builder): void
    {
        $this->addWithPending($builder);
        $this->addWithoutPending($builder);
        $this->addOnlyPending($builder);
    }

    /**
     * @param Builder<\Stancl\Tenancy\Contracts\Tenant&Model> $builder
     */
    protected function addWithPending(Builder $builder): void
    {
        $builder->macro('withPending', function (Builder $builder, $withPending = true) {
            if (! $withPending) {
                return $builder->withoutPending();
            }

            return $builder->withoutGlobalScope(static::class);
        });
    }

    /**
     * @param Builder<\Stancl\Tenancy\Contracts\Tenant&Model> $builder
     */
    protected function addWithoutPending(Builder $builder): void
    {
        $builder->macro('withoutPending', function (Builder $builder) {
            $builder->withoutGlobalScope(static::class)
                ->whereNull($builder->getModel()->getColumnForQuery('pending_since'))
                ->orWhereNull($builder->getModel()->getDataColumn());

            return $builder;
        });
    }

    /**
     * @param Builder<\Stancl\Tenancy\Contracts\Tenant&Model> $builder
     */
    protected function addOnlyPending(Builder $builder): void
    {
        $builder->macro('onlyPending', function (Builder $builder) {
            $builder->withoutGlobalScope(static::class)->whereNotNull($builder->getModel()->getColumnForQuery('pending_since'));

            return $builder;
        });
    }
}
