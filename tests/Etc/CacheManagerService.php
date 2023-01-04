<?php

declare(strict_types=1);

namespace Stancl\Tenancy\Tests\Etc;

use Illuminate\Cache\CacheManager;
use Illuminate\Cache\Repository;

class CacheManagerService
{
    public Repository|null $cache = null;

    public function __construct(CacheManager $cacheManager)
    {
        $this->cache = $cacheManager->driver('redis2');
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
