<?php

declare(strict_types=1);

namespace Stancl\Tenancy\Database\Concerns;

use Stancl\Tenancy\Contracts\Syncable;

trait TriggerSyncEvent
{
    public static function booted(): void
    {
        static::saved(function (self $pivot) {
            $parent = $pivot->pivotParent;

            if ($parent instanceof Syncable && $parent->shouldSync()) {
                $parent->triggerSyncEvent();
            }
        });
    }
}
