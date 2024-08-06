<?php

declare(strict_types=1);

namespace Stancl\Tenancy\ResourceSyncing;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Stancl\Tenancy\Contracts\Tenant;

// todo@move move all resource syncing-related things to a separate namespace?

/**
 * @property-read Tenant[]|Collection $tenants
 */
interface SyncMaster extends Syncable
{
    /**
     * @return BelongsToMany<Tenant&Model>
     */
    public function tenants(): BelongsToMany;

    public function getTenantModelName(): string;

    public function triggerDetachEvent(Tenant&Model $tenant): void;

    public function triggerAttachEvent(Tenant&Model $tenant): void;

    public function triggerDeleteEvent(bool $forceDelete = false): void;

    public function triggerRestoredEvent(): void;
}
