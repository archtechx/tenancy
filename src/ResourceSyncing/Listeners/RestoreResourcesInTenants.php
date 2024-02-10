<?php

declare(strict_types=1);

namespace Stancl\Tenancy\ResourceSyncing\Listeners;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Stancl\Tenancy\Listeners\QueueableListener;
use Stancl\Tenancy\ResourceSyncing\Events\SyncMasterRestored;
use Stancl\Tenancy\ResourceSyncing\Syncable;
use Stancl\Tenancy\ResourceSyncing\SyncMaster;

class RestoreResourcesInTenants extends QueueableListener
{
    public static bool $shouldQueue = false;

    public function handle(SyncMasterRestored $event): void
    {
        /** @var SyncMaster&Model $centralResource */
        $centralResource = $event->centralResource;

        if (! $centralResource::hasMacro('withTrashed')) {
            return;
        }

        tenancy()->runForMultiple($centralResource->tenants()->cursor(), function () use ($centralResource) {
            $tenantResourceClass = $centralResource->getTenantModelName();
            /**
             * @var Syncable $centralResource
             * @var (SoftDeletes&Syncable)|null $tenantResource
             */
            $tenantResource = $tenantResourceClass::withTrashed()->firstWhere(
                $centralResource->getGlobalIdentifierKeyName(),
                $centralResource->getGlobalIdentifierKey()
            );

            if ($tenantResource) {
                $tenantResource->restore();
            }
        });
    }
}
