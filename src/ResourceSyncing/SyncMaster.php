<?php

declare(strict_types=1);

namespace Stancl\Tenancy\ResourceSyncing;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Stancl\Tenancy\Database\Contracts\TenantWithDatabase;

/**
 * @property-read TenantWithDatabase[]|Collection<int, TenantWithDatabase&Model> $tenants
 */
interface SyncMaster extends Syncable
{

    public function getTenantModelName(): string;

    /**
     * Should return the name of the relationship to the tenants table (e.g. 'tenants').
     *
     * In the class where this interface is implemented, the relationship method also has to be defined.
     */
    public function getTenantsRelationshipName(): string;

    public function triggerDetachEvent(TenantWithDatabase&Model $tenant): void;

    public function triggerAttachEvent(TenantWithDatabase&Model $tenant): void;

    public function triggerDeleteEvent(bool $forceDelete = false): void;

    public function triggerRestoredEvent(): void;
}
