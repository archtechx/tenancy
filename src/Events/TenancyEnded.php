<?php

namespace Stancl\Tenancy\Events;

use Stancl\Tenancy\Database\Models\Tenant;

class TenancyEnded
{
    /** @var Tenant */
    protected $tenant;

    public function __construct(Tenant $tenant)
    {
        $this->tenant = $tenant;
    }
}
