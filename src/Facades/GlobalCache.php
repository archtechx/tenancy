<?php

declare(strict_types=1);

namespace Stancl\Tenancy\Facades;

use Illuminate\Support\Facades\Cache;

class GlobalCache extends Cache
{
    /** Make sure this works identically to global_cache() */
    protected static $cached = false;

    protected static function getFacadeAccessor()
    {
        return 'globalCache';
    }
}
