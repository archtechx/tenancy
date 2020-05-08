<?php

namespace Stancl\Tenancy\Database\Models\Concerns;

trait GeneratesIds
{
    public static function bootGeneratesIds()
    {
        static::creating(function (self $model) {
            if (! $model->id && config('tenancy.id_generator')) {
                $model->id = app(config('tenancy.id_generator'))->generate($model);
            }
        });
    }
}
