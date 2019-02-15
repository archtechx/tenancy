<?php

namespace Stancl\Tenancy;

use Illuminate\Support\Facades\Facade;

class TenancyFacade extends Facade
{
    protected static function getFacadeAccessor()
    {
        return TenantManager::class;
    }
}
