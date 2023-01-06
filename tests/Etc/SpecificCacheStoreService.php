<?php

declare(strict_types=1);

namespace Stancl\Tenancy\Tests\Etc;

use Illuminate\Cache\CacheManager;
use Illuminate\Cache\Repository;

class SpecificCacheStoreService
{
    public Repository $cache;

    public function __construct(CacheManager $cacheManager, string $cacheStoreName)
    {
        $this->cache = $cacheManager->store($cacheStoreName);
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
