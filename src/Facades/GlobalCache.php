<?php

declare(strict_types=1);

namespace Stancl\Tenancy\Facades;

use Illuminate\Support\Facades\Facade;

class GlobalCache extends Facade
{
    protected static function getFacadeAccessor()
    {
        return 'globalCache';
    }
}
