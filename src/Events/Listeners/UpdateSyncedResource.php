<?php

namespace Stancl\Tenancy\Events\Listeners;

use Illuminate\Database\Eloquent\Model;
use Stancl\Tenancy\Contracts\SyncMaster;
use Stancl\Tenancy\Contracts\Tenant;
use Stancl\Tenancy\Events\SyncedResourceSaved;

class UpdateSyncedResource
{
    public function handle(SyncedResourceSaved $event)
    {
        $syncedAttributes = $event->model->only($event->model->getSyncedAttributeNames());

        // We update the central record only if the event comes from tenant context.
        if ($event->tenant) {
            /** @var Model|SyncMaster $centralModel */
            $centralModel = $event->model->getCentralModelName()
                ::where($event->model->getGlobalIdentifierKeyName(), $event->model->getGlobalIdentifierKey())
                ->first();
            
            // We disable events for this call, to avoid triggering this event & listener again.
            $centralModel->withoutEvents(function () use ($centralModel, $syncedAttributes) {
                $centralModel->update($syncedAttributes);
            });

            $tenants = $centralModel->tenants->except($event->tenant->getTenantKey());
        } else {
            $centralModel = $event->model;
            $tenants = $centralModel->tenants;
        }

        foreach ($tenants as $tenant) {
            // todo: performance optimization - $tenant->run() does tenancy()->end() after each call.
            // we dont want that when we want to initialize for the next tenant afterwards rather than for the previous tenant
            // so we should write a method like run() for running things on multiple tenants efficiently

            $tenant->run(function () use ($event, $syncedAttributes) {
                // Forget instance state and find the model,
                // again in the current tenant's context.

                /** @var Tenant|Model $model */                
                $localModel = $event->model::find($event->model->getKey());

                // Also: We're syncing attributes, not columns, which is
                // why we're using Eloquent instead of direct DB queries.

                // We disable events for this call, to avoid triggering this event & listener again.
                $localModel->update($syncedAttributes);
            });
        }
    }
}
