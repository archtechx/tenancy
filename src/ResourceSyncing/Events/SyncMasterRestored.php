<?php

declare(strict_types=1);

namespace Stancl\Tenancy\ResourceSyncing\Events;

use Illuminate\Database\Eloquent\Model;
use Stancl\Tenancy\ResourceSyncing\SyncMaster;

class SyncMasterRestored
{
    public function __construct(
        public SyncMaster&Model $centralResource
    ) {}
}
