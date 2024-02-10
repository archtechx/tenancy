<?php

declare(strict_types=1);

namespace Stancl\Tenancy\ResourceSyncing;

use Exception;

class CentralResourceNotAvailableInPivotException extends Exception
{
    public function __construct()
    {
        parent::__construct(
            'Central resource is not accessible in pivot model.
            To attach a resource to a tenant, use $centralResource->tenants()->attach($tenant) instead of $tenant->resources()->attach($centralResource) (same for detaching).
            To make this work both ways, you can make your pivot implement PivotWithRelation and return the related model in getRelatedModel() or extend MorphPivot.'
        );
    }
}
