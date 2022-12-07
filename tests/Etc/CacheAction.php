<?php

declare(strict_types=1);

namespace Stancl\Tenancy\Tests\Etc;

use Illuminate\Cache\Repository;

class CacheAction
{
    public function __construct(
       protected Repository $cache
    ){
    }

    public function handle(): void
    {
        if (tenancy()->initialized) {
            $this->cache->put('key', tenant()->getTenantKey());
        } else {
            $this->cache->put('key', 'central-value');
        }
    }
}
