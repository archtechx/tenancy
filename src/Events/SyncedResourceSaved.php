<?php

namespace Stancl\Tenancy\Events;

use Illuminate\Database\Eloquent\Model;
use Stancl\Tenancy\Contracts\Syncable;
use Stancl\Tenancy\Contracts\Tenant;

class SyncedResourceSaved
{
    /** @var Syncable|Model */
    public $model;

    /** @var Tenant|Model|null */
    public $tenant;

    public function __construct(Syncable $model, ?Tenant $tenant)
    {
        $this->model = $model;
        $this->tenant = $tenant;
    }
}
