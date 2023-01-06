<?php

declare(strict_types=1);

namespace Stancl\Tenancy\Events;

use Illuminate\Database\Eloquent\Model;
use Stancl\Tenancy\Contracts\Syncable;
use Stancl\Tenancy\Database\Contracts\TenantWithDatabase;

class SyncedResourceSaved
{
    public function __construct(
        public Syncable&Model $model,
        public TenantWithDatabase|null $tenant,
    ) {
    }
}
