<?php

declare(strict_types=1);

namespace Stancl\Tenancy\ResourceSyncing\Listeners;

use Stancl\Tenancy\Listeners\QueueableListener;
use Stancl\Tenancy\ResourceSyncing\Events\CentralResourceDetachedFromTenant;

/**
 * When a central resource is detached from a tenant, delete the tenant resource.
 */
class DeleteResourceInTenant extends QueueableListener
{
    use DeletesSyncedResources;

    public static bool $shouldQueue = false;

    public function handle(CentralResourceDetachedFromTenant $event): void
    {
        tenancy()->run($event->tenant, fn () => $this->deleteSyncedResource($event->centralResource, true));
    }
}
