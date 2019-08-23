<?php

declare(strict_types=1);

namespace Stancl\Tenancy;

use Illuminate\Support\Facades\Facade;

class TenancyFacade extends Facade
{
    protected static function getFacadeAccessor()
    {
        return TenantManager::class;
    }
}
