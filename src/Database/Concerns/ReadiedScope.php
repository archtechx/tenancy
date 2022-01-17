<?php

declare(strict_types=1);

namespace Stancl\Tenancy\Database\Concerns;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

class ReadiedScope implements Scope
{

    /**
     * All of the extensions to be added to the builder.
     *
     * @var string[]
     */
    protected $extensions = ['WithReadied', 'WithoutReadied', 'OnlyReadied'];

    /**
     * Apply the scope to a given Eloquent query builder.
     *
     * @param  Builder  $builder
     * @param  Model  $model
     * @return void
     */
    public function apply(Builder $builder, Model $model)
    {
        $builder->when(!config('tenancy.readied.include_in_scope'), function (Builder $builder){
            $builder->whereNull('data->readied');
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
     * Add the with-readied extension to the builder.
     *
     * @param  \Illuminate\Database\Eloquent\Builder  $builder
     * @return void
     */
    protected function addWithReadied(Builder $builder)
    {
        $builder->macro('withReadied', function (Builder $builder, $withReadied = true) {
            if (! $withReadied) {
                return $builder->withoutReadied();
            }

            return $builder->withoutGlobalScope($this);
        });
    }

    /**
     * Add the without-readied extension to the builder.
     *
     * @param  \Illuminate\Database\Eloquent\Builder  $builder
     * @return void
     */
    protected function addWithoutReadied(Builder $builder)
    {
        $builder->macro('withoutReadied', function (Builder $builder) {

            $builder->withoutGlobalScope($this)->whereNull('data->readied');

            return $builder;
        });
    }

    /**
     * Add the only-readied extension to the builder.
     *
     * @param  \Illuminate\Database\Eloquent\Builder  $builder
     * @return void
     */
    protected function addOnlyReadied(Builder $builder)
    {
        $builder->macro('onlyReadied', function (Builder $builder) {

            $builder->withoutGlobalScope($this)->whereNotNull('data->readied');

            return $builder;
        });
    }
}
