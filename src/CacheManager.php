<?php

namespace Stancl\Tenancy;

use Illuminate\Cache\CacheManager as BaseCacheManager;

class CacheManager extends BaseCacheManager
{
    public function __call($method, $parameters)
    {
        $tags = [config('tenancy.cache.prefix_base') . tenant('uuid')];
        
        if ($method === "tags") {
            return $this->store()->tags(array_merge($tags, ...$parameters));
        }

        return $this->store()->tags($tags)->$method(...$parameters);
    }
}
