<?php

declare(strict_types=1);

namespace Stancl\Tenancy\ResourceSyncing\Listeners;

use Closure;
use Illuminate\Contracts\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Pivot;
use Illuminate\Queue\SerializesModels;
use Stancl\Tenancy\Contracts\Tenant;
use Stancl\Tenancy\Database\Contracts\TenantWithDatabase;
use Stancl\Tenancy\Database\TenantCollection;
use Stancl\Tenancy\Listeners\QueueableListener;
use Stancl\Tenancy\ResourceSyncing\Events\SyncedResourceSaved;
use Stancl\Tenancy\ResourceSyncing\Events\SyncedResourceSavedInForeignDatabase;
use Stancl\Tenancy\ResourceSyncing\ModelNotSyncMasterException;
use Stancl\Tenancy\ResourceSyncing\ParsesCreationAttributes;
use Stancl\Tenancy\ResourceSyncing\Syncable;
use Stancl\Tenancy\ResourceSyncing\SyncMaster;
use Stancl\Tenancy\Tenancy;

class UpdateOrCreateSyncedResource extends QueueableListener
{
    use SerializesModels, ParsesCreationAttributes;

    public static bool $shouldQueue = false;

    /**
     * This static property allows you to scope the "get model query"
     * that's responsible for finding the resources that should get synced (in the getModel() method).
     *
     * For example, to include soft deleted records while syncing (excluded by default), you can use this closure:
     *
     * UpdateOrCreateSyncedResource::$scopeGetModelQuery = function (Builder $query) {
     *     if ($query->hasMacro('withTrashed')) {
     *        $query->withTrashed();
     *     }
     * };
     */
    public static Closure|null $scopeGetModelQuery = null;

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
        $centralModelClass = $event->model->getCentralModelName();

        $centralModel = $this->getModel($centralModelClass, $event->model);

        // We disable events for this call, to avoid triggering this event & listener again.
        $centralModelClass::withoutEvents(function () use (&$centralModel, $syncedAttributes, $event, $centralModelClass) {
            if ($centralModel) {
                $centralModel->update($syncedAttributes);
            } else {
                // If the resource doesn't exist at all in the central DB, we create it
                $centralModel = $centralModelClass::create($this->parseCreationAttributes($event->model));
            }

            event(new SyncedResourceSavedInForeignDatabase($centralModel, null));
        });

        // If the model was just created, the mapping of the tenant to the user likely doesn't exist, so we create it.
        $currentTenantMapping = function ($model) use ($event) {
            /** @var TenantWithDatabase */
            $tenant = $event->tenant;

            return ((string) $model->pivot->getAttribute(Tenancy::tenantKeyColumn())) === ((string) $tenant->getTenantKey());
        };

        $mappingExists = $centralModel->tenants->contains($currentTenantMapping);

        if (! $mappingExists) {
            // Here we should call TenantPivot, but we call general Pivot, so that this works
            // even if people use their own pivot model that is not based on our TenantPivot
            Pivot::withoutEvents(function () use ($centralModel, $event) {
                /** @var TenantWithDatabase */
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
            // Forget instance state and find the model again,
            // in the current tenant's context.

            /** @var Model&Syncable $eventModel */
            $eventModel = $event->model;

            if ($eventModel instanceof SyncMaster) {
                // If event model comes from central DB, we get the tenant model name to run the query
                $localModelClass = $eventModel->getTenantModelName();
            } else {
                $localModelClass = get_class($eventModel);
            }

            $localModel = $this->getModel($localModelClass, $eventModel);

            // Also: We're syncing attributes, not columns, which is
            // why we're using Eloquent instead of direct DB queries.

            // We disable events for this call, to avoid triggering this event & listener again.
            $localModelClass::withoutEvents(function () use ($localModelClass, $localModel, $syncedAttributes, $eventModel, $tenant) {
                if ($localModel) {
                    $localModel->update($syncedAttributes);
                } else {
                    $localModel = $localModelClass::create($this->parseCreationAttributes($eventModel));
                }

                event(new SyncedResourceSavedInForeignDatabase($localModel, $tenant));
            });
        });
    }

    protected function getModel(string $modelClass, Syncable $eventModel): Model|null
    {
        /** @var Builder */
        $query = $modelClass::where($eventModel->getGlobalIdentifierKeyName(), $eventModel->getGlobalIdentifierKey());

        if (static::$scopeGetModelQuery) {
            (static::$scopeGetModelQuery)($query);
        }

        return $query->first();
    }
}
