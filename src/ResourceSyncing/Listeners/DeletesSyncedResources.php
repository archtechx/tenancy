<?php

declare(strict_types=1);

namespace Stancl\Tenancy\ResourceSyncing\Listeners;

use Illuminate\Database\Eloquent\Model;
use Stancl\Tenancy\ResourceSyncing\SyncMaster;
use Illuminate\Contracts\Database\Eloquent\Builder;

trait DeletesSyncedResources
{
    protected function deleteSyncedResource(SyncMaster&Model $centralResource, bool $force = false): void
    {
        $tenantResourceClass = $centralResource->getTenantModelName();

        /** @var Builder $query */
        $query = $tenantResourceClass::where(
            $centralResource->getGlobalIdentifierKeyName(),
            $centralResource->getGlobalIdentifierKey()
        );

        if ($force) {
            if ($query->hasMacro('withTrashed')) {
                $query->withTrashed(); // @phpstan-ignore method.notFound
            }

            $query->first()?->forceDelete();
        } else {
            $query->first()?->delete();
        }
    }
}
