<?php

declare(strict_types=1);

namespace Stancl\Tenancy\ResourceSyncing\Listeners;

use Stancl\Tenancy\Listeners\QueueableListener;
use Stancl\Tenancy\ResourceSyncing\Events\SyncMasterDeleted;

class DeleteResourcesInTenants extends QueueableListener
{
    use DeletesSyncedResources;

    public static bool $shouldQueue = false;

    public function handle(SyncMasterDeleted $event): void
    {
        $centralResource = $event->centralResource;
        $forceDelete = $event->forceDelete;

        tenancy()->runForMultiple($centralResource->tenants()->cursor(), function () use ($centralResource, $forceDelete) {
            $this->deleteSyncedResource($centralResource, $forceDelete);
        });
    }
}
