<?php

declare(strict_types=1);

namespace Stancl\Tenancy\Events;

use Illuminate\Database\Eloquent\Model;
use Stancl\Tenancy\Contracts\Syncable;
use Stancl\Tenancy\Database\Contracts\TenantWithDatabase;

class SyncedResourceSaved
{
    public Syncable&Model $model;

    /** @var (TenantWithDatabase&Model)|null */
    public TenantWithDatabase|null $tenant;

    public function __construct(Syncable $model, TenantWithDatabase|null $tenant)
    {
        $this->model = $model;
        $this->tenant = $tenant;
    }
}
