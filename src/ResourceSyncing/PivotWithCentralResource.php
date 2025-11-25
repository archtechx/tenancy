<?php

declare(strict_types=1);

namespace Stancl\Tenancy\ResourceSyncing;

interface PivotWithCentralResource
{
    /** @return class-string<\Illuminate\Database\Eloquent\Model&Syncable> */
    public function getCentralResourceClass(): string;
}
