<?php

declare(strict_types=1);

namespace Stancl\Tenancy\ResourceSyncing;

use Illuminate\Database\Eloquent\Relations\MorphPivot;

class TenantMorphPivot extends MorphPivot
{
    use TriggerSyncingEvents;
}
