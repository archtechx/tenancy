<?php

declare(strict_types=1);

namespace Stancl\Tenancy\Database\Concerns;

use Stancl\Tenancy\Contracts\Syncable;
use Stancl\Tenancy\Contracts\UniqueIdentifierGenerator;
use Stancl\Tenancy\Events\SyncedResourceSaved;

trait ResourceSyncing
{
    public static function bootResourceSyncing()
    {
        static::saved(function (Syncable $model) {
            /** @var ResourceSyncing $model */
            if ($model->isSyncEnabled()) {
                $model->triggerSyncEvent();
            }
        });

        static::creating(function (self $model) {
            $key = $model->getGlobalIdentifierKeyName();

            if (! $model->getAttribute($key) && app()->bound(UniqueIdentifierGenerator::class) && $model->isSyncEnabled()) {
                $model->setAttribute($key, app(UniqueIdentifierGenerator::class)->generate($model));
            }
        });
    }

    public function triggerSyncEvent()
    {
        /** @var Syncable $this */
        event(new SyncedResourceSaved($this, tenant()));
    }

    public function isSyncEnabled()
    {
        return true;
    }
}
