<?php

declare(strict_types=1);

namespace Stancl\Tenancy\Database\Concerns;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

class PendingScope implements Scope
{

    /**
     * All of the extensions to be added to the builder.
     *
     * @var string[]
     */
    protected $extensions = ['WithPending', 'WithoutPending', 'OnlyPending'];

    /**
     * Apply the scope to a given Eloquent query builder.
     *
     * @param  Builder  $builder
     * @param  Model  $model
     * @return void
     */
    public function apply(Builder $builder, Model $model)
    {
        $builder->when(!config('tenancy.pending.include_in_queries'), function (Builder $builder){
            $builder->whereNull('data->pending_since');
        });
    }

    /**
     * Extend the query builder with the needed functions.
     *
     * @param  \Illuminate\Database\Eloquent\Builder  $builder
     * @return void
     */
    public function extend(Builder $builder)
    {
        foreach ($this->extensions as $extension) {
            $this->{"add{$extension}"}($builder);
        }
    }
    /**
     * Add the with-pending extension to the builder.
     *
     * @param  \Illuminate\Database\Eloquent\Builder  $builder
     * @return void
     */
    protected function addWithPending(Builder $builder)
    {
        $builder->macro('withPending', function (Builder $builder, $withPending = true) {
            if (! $withPending) {
                return $builder->withoutPending();
            }

            return $builder->withoutGlobalScope($this);
        });
    }

    /**
     * Add the without-pending extension to the builder.
     *
     * @param  \Illuminate\Database\Eloquent\Builder  $builder
     * @return void
     */
    protected function addWithoutPending(Builder $builder)
    {
        $builder->macro('withoutPending', function (Builder $builder) {

            // Only use whereNull('data->pending_since') when Laravel 6 support is dropped
            // Issue fixed in Laravel 7 https://github.com/laravel/framework/pull/32417
            $builder->withoutGlobalScope($this)
                ->where('data->pending_since', 'like', 'null')
                ->orWhereNull('data');

            return $builder;
        });
    }

    /**
     * Add the only-pending extension to the builder.
     *
     * @param  \Illuminate\Database\Eloquent\Builder  $builder
     * @return void
     */
    protected function addOnlyPending(Builder $builder)
    {
        $builder->macro('onlyPending', function (Builder $builder) {

            // Use whereNotNull when Laravel 6 is dropped
            // Issue fixed in Laravel 7 https://github.com/laravel/framework/pull/32417
            $builder->withoutGlobalScope($this)->where('data->pending_since', 'not like', 'null');

            return $builder;
        });
    }
}
