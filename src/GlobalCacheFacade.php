<?php

declare(strict_types=1);

namespace Stancl\Tenancy;

use Illuminate\Support\Facades\Facade;

class GlobalCacheFacade extends Facade
{
    protected static function getFacadeAccessor()
    {
        return 'globalCache';
    }
}
