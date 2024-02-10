<?php

declare(strict_types=1);

namespace Stancl\Tenancy\ResourceSyncing;

use Illuminate\Database\Eloquent\Relations\Pivot;

class TenantPivot extends Pivot
{
    use TriggerSyncingEvents;
}
