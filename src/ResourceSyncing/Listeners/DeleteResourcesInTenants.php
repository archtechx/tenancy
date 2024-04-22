<?php

declare(strict_types=1);

namespace Stancl\Tenancy\ResourceSyncing\Listeners;

use Illuminate\Database\Eloquent\SoftDeletes;
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

            // Delete pivot records if the central resource doesn't use soft deletes
            // or the central resource was deleted using forceDelete()
            if ($forceDelete || ! in_array(SoftDeletes::class, class_uses_recursive($centralResource::class), true)) {
                $centralResource->tenants()->detach(tenant());
            }
        });
    }
}
