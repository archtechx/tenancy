<?php

namespace Stancl\Tenancy\Database\Models;

use Illuminate\Database\Eloquent\Relations\Pivot;
use Stancl\Tenancy\Contracts\Syncable;

class TenantPivot extends Pivot
{
    public static function booted()
    {
        static::saved(function (self $pivot) {
            $parent = $pivot->pivotParent;

            if ($parent instanceof Syncable) {
                $parent->triggerSyncEvent();
            }
        });
    }
}