<?php

declare(strict_types=1);

namespace Stancl\Tenancy\Database\Models;

use Illuminate\Database\Eloquent\Relations\Pivot;
use Stancl\Tenancy\Contracts\Syncable;

class TenantPivot extends Pivot
{
    public static function booted(): void
    {
        static::saved(function (self $pivot) {
            $parent = $pivot->pivotParent;

            if ($parent instanceof Syncable) {
                $parent->triggerSyncEvent();
            }
        });
    }
}
