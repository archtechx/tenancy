<?php

declare(strict_types=1);

namespace Stancl\Tenancy\Contracts;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

// todo move all resource syncing-related things to a separate namespace?

/**
 * @property-read Tenant[]|Collection $tenants
 */
interface SyncMaster extends Syncable
{
    public function tenants(): BelongsToMany;

    public function getTenantModelName(): string;
}
