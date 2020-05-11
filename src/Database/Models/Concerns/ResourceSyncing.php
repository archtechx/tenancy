<?php

namespace Stancl\Tenancy\Database\Models\Concerns;

use Stancl\Tenancy\Contracts\Syncable;
use Stancl\Tenancy\Events\SyncedResourceSaved;

trait ResourceSyncing
{
    public static function bootResourceSyncing()
    {
        static::saving(function (Syncable $model) {
            event(new SyncedResourceSaved($model, tenant()));
        });
    }
}
