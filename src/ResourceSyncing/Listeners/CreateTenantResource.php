<?php

declare(strict_types=1);

namespace Stancl\Tenancy\ResourceSyncing\Listeners;

use Stancl\Tenancy\Listeners\QueueableListener;
use Stancl\Tenancy\ResourceSyncing\Events\CentralResourceAttachedToTenant;
use Stancl\Tenancy\ResourceSyncing\Events\SyncedResourceSavedInForeignDatabase;
use Stancl\Tenancy\ResourceSyncing\ParsesCreationAttributes;

/**
 * Create tenant resource synced to the central resource.
 */
class CreateTenantResource extends QueueableListener
{
    use ParsesCreationAttributes;

    public static bool $shouldQueue = false;

    public function handle(CentralResourceAttachedToTenant $event): void
    {
        $tenantResourceClass = $event->centralResource->getTenantModelName();

        tenancy()->run($event->tenant, function () use ($event, $tenantResourceClass) {
            // Prevent $tenantResourceClass::create() from firing the SyncedResourceSaved event
            // Manually fire the SyncedResourceSavedInForeignDatabase event instead
            $tenantResourceClass::withoutEvents(function () use ($event, $tenantResourceClass) {
                $tenantResource = $tenantResourceClass::create($this->parseCreationAttributes($event->centralResource));

                event(new SyncedResourceSavedInForeignDatabase($tenantResource, $event->tenant));
            });
        });
    }
}
