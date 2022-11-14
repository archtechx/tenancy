<?php

declare(strict_types=1);

namespace Stancl\Tenancy\Database\Models;

use Illuminate\Database\Eloquent\Relations\Pivot;
use Stancl\Tenancy\Database\Concerns\TriggerSyncEvent;

class TenantPivot extends Pivot
{
    use TriggerSyncEvent;
}
