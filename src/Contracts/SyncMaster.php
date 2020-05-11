<?php

namespace Stancl\Tenancy\Contracts;

use Illuminate\Database\Eloquent\Collection;

// todo rename?
/**
 * @property-read Tenant[]|Collection $tenants
 */
interface SyncMaster extends Syncable
{
    public function tenants(); // Probably should return BelongsToMany
}