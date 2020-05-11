<?php

namespace Stancl\Tenancy\Database\Models\Concerns;

use Stancl\Tenancy\Contracts\UniqueIdentifierGenerator;

trait GeneratesIds
{
    public static function bootGeneratesIds()
    {
        static::creating(function (self $model) {
            if (! $model->id && app()->bound(UniqueIdentifierGenerator::class)) {
                $model->id = app(UniqueIdentifierGenerator::class)->generate($model);
            }
        });
    }
}
