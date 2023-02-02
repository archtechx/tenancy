<?php

declare(strict_types=1);

namespace Stancl\Tenancy\Database\Models;

use Illuminate\Database\Eloquent\Relations\MorphPivot;
use Stancl\Tenancy\Database\Concerns\TriggerSyncEvent;

class TenantMorphPivot extends MorphPivot
{
    use TriggerSyncEvent;
}
