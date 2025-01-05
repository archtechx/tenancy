<?php

declare(strict_types=1);

namespace Stancl\Tenancy\ResourceSyncing;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphPivot;
use Illuminate\Database\Eloquent\Relations\Pivot;
use Stancl\Tenancy\Contracts\Tenant;
use Stancl\Tenancy\Database\Contracts\TenantWithDatabase;

/**
 * Used on pivot models.
 *
 * @see TenantPivot
 * @see MorphPivot
 */
trait TriggerSyncingEvents
{
    public static function bootTriggerSyncingEvents(): void
    {
        static::saving(function (self $pivot) {
            // Try getting the central resource to see if it is available
            // If it is not available, throw an exception to interrupt the saving process
            // And prevent creating a pivot record without a central resource
            $pivot->getCentralResourceAndTenant();
        });

        static::saved(function (self $pivot) {
            /**
             * @var static&Pivot $pivot
             * @var SyncMaster|null $centralResource
             * @var (TenantWithDatabase&Model)|null $tenant
             */
            [$centralResource, $tenant] = $pivot->getCentralResourceAndTenant();

            if ($tenant && $centralResource?->shouldSync()) {
                $centralResource->triggerAttachEvent($tenant);
            }
        });

        static::deleting(function (self $pivot) {
            /**
             * @var static&Pivot $pivot
             * @var SyncMaster|null $centralResource
             * @var (TenantWithDatabase&Model)|null $tenant
             */
            [$centralResource, $tenant] = $pivot->getCentralResourceAndTenant();

            if ($tenant && $centralResource?->shouldSync()) {
                $centralResource->triggerDetachEvent($tenant);
            }
        });
    }

    public function getCentralResourceAndTenant(): array
    {
        /** @var $this&Pivot $this */
        $parent = $this->pivotParent;

        if ($parent instanceof Tenant) {
            // Tenant is the parent
            // $tenant->attach($resource) / $tenant->detach($resource)
            return [$this->findCentralResource(), $parent];
        }

        // Central resource is the parent
        // $centralResource->attach($tenant) / $centralResource->detach($tenant)
        return [$parent, tenancy()->find($this->{$this->getOtherKey()})];
    }

    /**
     * Get the resource class if available. Otherwise, throw an exception.
     *
     * Used in the `findCentralResource` method.
     *
     * @throws CentralResourceNotAvailableInPivotException
     */
    protected function getResourceClass(): string
    {
        /** @var $this&(Pivot|MorphPivot|((Pivot|MorphPivot)&PivotWithRelation)) $this */
        if ($this instanceof PivotWithRelation) {
            return $this->getRelatedModel()::class;
        }

        if ($this instanceof MorphPivot) {
            return $this->morphClass;
        }

        throw new CentralResourceNotAvailableInPivotException;
    }

    protected function findCentralResource(): (SyncMaster&Model)|null
    {
        /**
         * Create an instance of the central resource class so that we can get the global identifier key name properly.
         *
         * @var SyncMaster&Model $centralResourceModel
         */
        $centralResourceModel = new ($this->getResourceClass());

        $globalId = $this->{$this->getOtherKey()};

        /** @var (SyncMaster&Model)|null $centralResource */
        $centralResource = $centralResourceModel::firstWhere($centralResourceModel->getGlobalIdentifierKeyName(), $globalId);

        return $centralResource;
    }
}
