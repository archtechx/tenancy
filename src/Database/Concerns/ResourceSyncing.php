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
            if ($model->shouldSync()) {
                $model->triggerSyncEvent();
            }
        });

        static::creating(function (self $model) {
            $keyName = $model->getGlobalIdentifierKeyName();

            if (! $model->getAttribute($keyName) && app()->bound(UniqueIdentifierGenerator::class)) {
                $model->setAttribute($keyName, app(UniqueIdentifierGenerator::class)->generate($model));
            }
        });
    }

    public function triggerSyncEvent()
    {
        /** @var Syncable $this */
        event(new SyncedResourceSaved($this, tenant()));
    }

    public function shouldSync(): bool
    {
        return true;
    }
}
