<?php

namespace Stancl\Tenancy\Database\Models;

use Illuminate\Database\Eloquent\Relations\Pivot;

class TenantPivot extends Pivot
{
    public static function booted()
    {
        static::saved(function (self $pivot) {
            $pivot->pivotParent->triggerSyncEvent();
        });
    }
}