<?php

namespace Stancl\Tenancy\Database\Concerns;

trait ConvertsDomainsToLowercase
{
    public static function bootConvertsDomainsToLowercase()
    {
        static::saving(function ($model) {
            $model->domain = strtolower($model->domain);
        });
    }
}