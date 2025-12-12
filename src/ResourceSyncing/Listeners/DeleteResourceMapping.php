<?php

declare(strict_types=1);

namespace Stancl\Tenancy\ResourceSyncing\Listeners;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Pivot;
use Illuminate\Database\Eloquent\SoftDeletes;
use Stancl\Tenancy\Listeners\QueueableListener;
use Stancl\Tenancy\ResourceSyncing\Events\SyncedResourceDeleted;
use Stancl\Tenancy\ResourceSyncing\Syncable;
use Stancl\Tenancy\ResourceSyncing\SyncMaster;

/**
 * Deletes pivot records when a synced resource is deleted.
 *
 * If a SyncMaster (central resource) is deleted, all pivot records for that resource are deleted.
 * If a Syncable (tenant resource) is deleted, only delete the pivot record for that tenant.
 */
class DeleteResourceMapping extends QueueableListener
{
    public static bool $shouldQueue = false;

    public function handle(SyncedResourceDeleted $event): void
    {
        $centralResource = $this->getCentralResource($event->model);

        if (! $centralResource) {
            return;
        }

        // Delete pivot records if the central resource doesn't use soft deletes
        // or the central resource was deleted using forceDelete()
        if ($event->forceDelete || ! in_array(SoftDeletes::class, class_uses_recursive($centralResource::class), true)) {
            Pivot::withoutEvents(function () use ($centralResource, $event) {
                // If detach() is called with null -- if $event->tenant is null -- this means a central resource was deleted and detaches all tenants.
                // If detach() is called with a specific tenant, it means the resource was deleted in that tenant, and we only delete that single mapping.
                $centralResource->tenants()->detach($event->tenant);
            });
        }
    }

    public function getCentralResource(Syncable&Model $resource): SyncMaster|null
    {
        if ($resource instanceof SyncMaster) {
            return $resource;
        }

        $centralResourceClass = $resource->getCentralModelName();

        /** @var (SyncMaster&Model)|null $centralResource */
        $centralResource = $centralResourceClass::firstWhere(
            $resource->getGlobalIdentifierKeyName(),
            $resource->getGlobalIdentifierKey()
        );

        return $centralResource;
    }
}
