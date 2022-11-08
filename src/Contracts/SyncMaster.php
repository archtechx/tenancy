<?php

declare(strict_types=1);

namespace Stancl\Tenancy\Contracts;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Relations\MorphToMany;

// todo move all resource syncing-related things to a separate namespace?

/**
 * @property-read Tenant[]|Collection $tenants
 */
interface SyncMaster extends Syncable
{
    public function resources(): MorphToMany;

    public function getTenantModelName(): string;
}
