<?php

declare(strict_types=1);

namespace Stancl\Tenancy\Facades;

use Illuminate\Support\Facades\Facade;
use Stancl\Tenancy\Tenant as Tenant;

class TenantFacade extends Facade
{
    protected static function getFacadeAccessor()
    {
        return Tenant::class;
    }
}
