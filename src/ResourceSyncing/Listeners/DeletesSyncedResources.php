<?php

declare(strict_types=1);

namespace Stancl\Tenancy\ResourceSyncing\Listeners;

use Illuminate\Database\Eloquent\Model;
use Stancl\Tenancy\ResourceSyncing\Syncable;
use Stancl\Tenancy\ResourceSyncing\SyncMaster;

trait DeletesSyncedResources
{
    protected function deleteSyncedResource(SyncMaster&Model $centralResource, bool $force = false): void
    {
        $tenantResourceClass = $centralResource->getTenantModelName();

        /** @var (Syncable&Model)|null $tenantResource */
        $tenantResource = $tenantResourceClass::firstWhere(
            $centralResource->getGlobalIdentifierKeyName(),
            $centralResource->getGlobalIdentifierKey()
        );

        if ($force) {
            $tenantResource?->forceDelete();
        } else {
            $tenantResource?->delete();
        }
    }
}
