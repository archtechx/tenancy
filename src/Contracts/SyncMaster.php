<?php

declare(strict_types=1);

namespace Stancl\Tenancy\Contracts;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * @property-read Tenant[]|Collection $tenants
 */
interface SyncMaster extends BaseSyncable
{
    public function tenants(): BelongsToMany;

    public function getTenantModelName(): string;

    public function getTenantModelFillable(): array;
}
