<?php

declare(strict_types=1);

namespace Stancl\Tenancy\Tests\Etc\ResourceSyncing;

use Stancl\Tenancy\ResourceSyncing\PivotWithCentralResource;
use Stancl\Tenancy\ResourceSyncing\TenantPivot;

class CustomPivot extends TenantPivot implements PivotWithCentralResource
{
    public function getCentralResourceClass(): string
    {
        return CentralUser::class;
    }
}
