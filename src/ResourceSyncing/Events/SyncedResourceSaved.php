<?php

declare(strict_types=1);

namespace Stancl\Tenancy\ResourceSyncing\Events;

use Illuminate\Database\Eloquent\Model;
use Stancl\Tenancy\Database\Contracts\TenantWithDatabase;
use Stancl\Tenancy\ResourceSyncing\Syncable;

class SyncedResourceSaved
{
    public function __construct(
        public Syncable&Model $model,
        public TenantWithDatabase|null $tenant,
    ) {}
}
