<?php

namespace Stancl\Tenancy;

use Illuminate\Support\Facades\Facade;

class GlobalCacheFacade extends Facade
{
    protected static function getFacadeAccessor()
    {
        return 'globalCache';
    }
}