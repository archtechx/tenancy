<?php

declare(strict_types=1);

namespace Stancl\Tenancy\ResourceSyncing;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Stancl\Tenancy\Database\Contracts\TenantWithDatabase;

/**
 * @property-read TenantWithDatabase[]|Collection<int, TenantWithDatabase&Model> $tenants
 */
interface SyncMaster extends Syncable
{
    /**
     * @return BelongsToMany<TenantWithDatabase&Model, self&Model>
     */
    public function tenants(): BelongsToMany;

    public function getTenantModelName(): string;

    public function triggerDetachEvent(TenantWithDatabase&Model $tenant): void;

    public function triggerAttachEvent(TenantWithDatabase&Model $tenant): void;

    public function triggerDeleteEvent(bool $forceDelete = false): void;

    public function triggerRestoredEvent(): void;
}
