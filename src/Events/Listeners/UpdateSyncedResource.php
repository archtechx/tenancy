<?php

namespace Stancl\Tenancy\Events\Listeners;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Pivot;
use Stancl\Tenancy\Contracts\SyncMaster;
use Stancl\Tenancy\Events\SyncedResourceSaved;
use Stancl\Tenancy\Exceptions\ModelNotSyncMaster;

class UpdateSyncedResource extends QueueableListener
{
    public static $shouldQueue = false;

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
            $event->model->getCentralModelName()::withoutEvents(function () use (&$centralModel, $syncedAttributes, $event) {
                if ($centralModel) {
                    $centralModel->update($syncedAttributes);
                } else {
                    // If the resource doesn't exist at all in the central DB,we create
                    // the record with all attributes, not just the synced ones.
                    $centralModel = $event->model->getCentralModelName()::create($event->model->getAttributes());
                }
            });

            // If the model was just created, the mapping of the tenant to the user likely doesn't exist, so we create it.
            $mappingExists = $centralModel->tenants->contains(function ($model) use ($event) {
                return $model->tenant_id === $event->tenant->getTenantKey();
            });

            if (! $mappingExists) {
                // Here we should call TenantPivot, but we call general Pivot, so that this works
                // even if people use their own pivot model that is not based on our TenantPivot
                Pivot::withoutEvents(function () use ($centralModel, $event) {
                    $centralModel->tenants()->attach($event->tenant->getTenantKey());
                });
            }

            $tenants = $centralModel->tenants->except($event->tenant->getTenantKey());
        } else {
            $centralModel = $event->model;

            if (! $centralModel instanceof SyncMaster) {
                // If we're trying to use a tenant User model instead of the central User model, for example.
                throw new ModelNotSyncMaster(get_class($centralModel));
           }

            /** @var SyncMaster|Model $centralModel */

            // Since this model is "dirty" (taken by reference from the event), it might have the tenants
            // relationship already loaded and cached. For this reason, we refresh the relationship.
            $centralModel->load('tenants');
            $tenants = $centralModel->tenants;
        }

        tenancy()->runForMultiple($tenants, function () use ($event, $syncedAttributes) {
            // Forget instance state and find the model,
            // again in the current tenant's context.

            $eventModel = $event->model;

            if ($eventModel instanceof SyncMaster) {
                // If event model comes from central DB, we get the tenant model name to run the query
                $localModelClass = $eventModel->getTenantModelName();
            } else {
                $localModelClass = get_class($eventModel);
            }

            /** @var Model|null */
            $localModel = $localModelClass::firstWhere($event->model->getGlobalIdentifierKeyName(), $event->model->getGlobalIdentifierKey());

            // Also: We're syncing attributes, not columns, which is
            // why we're using Eloquent instead of direct DB queries.

            // We disable events for this call, to avoid triggering this event & listener again.
            $localModelClass::withoutEvents(function () use ($localModelClass, $localModel, $syncedAttributes, $eventModel) {
                if ($localModel) {
                    $localModel->update($syncedAttributes);
                } else {
                    // When creating, we use all columns, not just the synced ones.
                    $localModelClass::create($eventModel->getAttributes());
                }
            });
        });
    }
}
