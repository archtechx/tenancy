<?php

declare(strict_types=1);

namespace Stancl\Tenancy\Listeners;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Pivot;
use Illuminate\Support\Arr;
use Stancl\Tenancy\Contracts\Syncable;
use Stancl\Tenancy\Contracts\SyncMaster;
use Stancl\Tenancy\Contracts\Tenant;
use Stancl\Tenancy\Database\TenantCollection;
use Stancl\Tenancy\Events\SyncedResourceChangedInForeignDatabase;
use Stancl\Tenancy\Events\SyncedResourceSaved;
use Stancl\Tenancy\Exceptions\ModelNotSyncMasterException;

class UpdateSyncedResource extends QueueableListener
{
    public static bool $shouldQueue = false;

    public function handle(SyncedResourceSaved $event): void
    {
        $syncedAttributes = $event->model->only($event->model->getSyncedAttributeNames());

        // We update the central record only if the event comes from tenant context.
        if ($event->tenant) {
            $tenants = $this->updateResourceInCentralDatabaseAndGetTenants($event, $syncedAttributes);
        } else {
            $tenants = $this->getTenantsForCentralModel($event->model);
        }

        $this->updateResourceInTenantDatabases($tenants, $event, $syncedAttributes);
    }

    protected function getTenantsForCentralModel(Syncable $centralModel): TenantCollection
    {
        if (! $centralModel instanceof SyncMaster) {
            // If we're trying to use a tenant User model instead of the central User model, for example.
            throw new ModelNotSyncMasterException(get_class($centralModel));
        }

        /** @var Tenant&Model&SyncMaster $centralModel */

        // Since this model is "dirty" (taken by reference from the event), it might have the tenants
        // relationship already loaded and cached. For this reason, we refresh the relationship.
        $centralModel->load('tenants');

        /** @var TenantCollection $tenants */
        $tenants = $centralModel->tenants;

        return $tenants;
    }

    protected function updateResourceInCentralDatabaseAndGetTenants(SyncedResourceSaved $event, array $syncedAttributes): TenantCollection
    {
        /** @var (Model&SyncMaster)|null $centralModel */
        $centralModel = $event->model->getCentralModelName()::where($event->model->getGlobalIdentifierKeyName(), $event->model->getGlobalIdentifierKey())
            ->first();

        // We disable events for this call, to avoid triggering this event & listener again.
        $event->model->getCentralModelName()::withoutEvents(function () use (&$centralModel, $syncedAttributes, $event) {
            if ($centralModel) {
                $centralModel->update($syncedAttributes);
                event(new SyncedResourceChangedInForeignDatabase($event->model, null));
            } else {
                // If the resource doesn't exist at all in the central DB,we create
                $centralModel = $event->model->getCentralModelName()::create($this->getAttributesForCreation($event->model));
                event(new SyncedResourceChangedInForeignDatabase($event->model, null));
            }
        });

        // If the model was just created, the mapping of the tenant to the user likely doesn't exist, so we create it.
        $currentTenantMapping = function ($model) use ($event) {
            /** @var Tenant */
            $tenant = $event->tenant;

            return ((string) $model->pivot->tenant_id) === ((string) $tenant->getTenantKey());
        };

        $mappingExists = $centralModel->tenants->contains($currentTenantMapping);

        if (! $mappingExists) {
            // Here we should call TenantPivot, but we call general Pivot, so that this works
            // even if people use their own pivot model that is not based on our TenantPivot
            Pivot::withoutEvents(function () use ($centralModel, $event) {
                /** @var Tenant */
                $tenant = $event->tenant;

                $centralModel->tenants()->attach($tenant->getTenantKey());
            });
        }

        /** @var TenantCollection $tenants */
        $tenants = $centralModel->tenants->filter(function ($model) use ($currentTenantMapping) {
            // Remove the mapping for the current tenant.
            return ! $currentTenantMapping($model);
        });

        return $tenants;
    }

    protected function updateResourceInTenantDatabases(TenantCollection $tenants, SyncedResourceSaved $event, array $syncedAttributes): void
    {
        tenancy()->runForMultiple($tenants, function ($tenant) use ($event, $syncedAttributes) {
            // Forget instance state and find the model,
            // again in the current tenant's context.

            /** @var Model&Syncable $eventModel */
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
            $localModelClass::withoutEvents(function () use ($localModelClass, $localModel, $syncedAttributes, $eventModel, $tenant) {
                if ($localModel) {
                    $localModel->update($syncedAttributes);
                } else {
                    $localModel = $localModelClass::create($this->getAttributesForCreation($eventModel));
                }

                event(new SyncedResourceChangedInForeignDatabase($localModel, $tenant));
            });
        });
    }

    protected function getAttributesForCreation(Model&Syncable $model): array
    {
        if (! $model->getSyncedCreationAttributes()) {
            // Creation attributes are not specified so create the model as 1:1 copy
            return $model->getAttributes();
        }

        if (Arr::isAssoc($model->getSyncedCreationAttributes())) {
            // Developer provided the default values (key => value) or mix of default values and attribute names (values only)
            // We will merge the default values with sync attributes
            [$attributes, $defaultValues] = $this->getAttributeNamesAndDefaultValues($model);

            return array_merge($model->only(array_merge($model->getSyncedAttributeNames(), $attributes)), $defaultValues);
        }

        // Developer provided the attribute names, so we'd use them to pick model attributes
        return $model->only($model->getSyncedCreationAttributes());
    }

    /**
     * Split the attribute names (sequential index items) and default values (key => values).
     */
    protected function getAttributeNamesAndDefaultValues(Model&Syncable $model): array
    {
        $syncedCreationAttributes = $model->getSyncedCreationAttributes() ?? [];

        $attributes = Arr::where($syncedCreationAttributes, function ($value, $key) {
            return is_numeric($key);
        });

        $defaultValues = Arr::where($syncedCreationAttributes, function ($value, $key) {
            return is_string($key);
        });

        return [$attributes, $defaultValues];
    }
}
