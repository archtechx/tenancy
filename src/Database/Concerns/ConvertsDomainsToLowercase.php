<?php

declare(strict_types=1);

namespace Stancl\Tenancy\Database\Concerns;

trait ConvertsDomainsToLowercase
{
    public static function bootConvertsDomainsToLowercase(): void
    {
        static::saving(function ($model) {
            if ($model->domain === '') {
                $model->domain = null;
            } elseif ($model->domain !== null) {
                $model->domain = strtolower($model->domain);
            }
        });
    }
}
