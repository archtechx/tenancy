<?php

declare(strict_types=1);

namespace Stancl\Tenancy\Facades;

use Illuminate\Support\Facades\Facade;
use Stancl\Tenancy\TenantManager;

class TenancyFacade extends Facade
{
    protected static function getFacadeAccessor()
    {
        return TenantManager::class;
    }
}
