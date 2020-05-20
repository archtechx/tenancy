<?php

namespace Stancl\Tenancy\Database\Concerns;

use Stancl\Tenancy\Contracts\UniqueIdentifierGenerator;

trait GeneratesIds
{
    public static function bootGeneratesIds()
    {
        static::creating(function (self $model) {
            if (! $model->getTenantKey() && app()->bound(UniqueIdentifierGenerator::class)) {
                $model->setAttribute($model->getTenantKeyName(), app(UniqueIdentifierGenerator::class)->generate($model));
            }
        });
    }

    public function getIncrementing()
    {
        return ! app()->bound(UniqueIdentifierGenerator::class);
    }
}
