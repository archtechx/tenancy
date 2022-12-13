<?php

declare(strict_types=1);

namespace Stancl\Tenancy;

use Illuminate\Cache\CacheManager as BaseCacheManager;
use Illuminate\Cache\Repository;

// todo move to Cache namespace?

class CacheManager extends BaseCacheManager
{
    public static bool $addTags = false;

    /**
     * Add tags and forward the call to the inner cache store.
     *
     * @param string $method
     * @param array $parameters
     */
    public function __call($method, $parameters)
    {
        if (! tenancy()->initialized || ! static::$addTags) {
            return parent::__call($method, $parameters);
        }

        $tags = [config('tenancy.cache.tag_base') . tenant()?->getTenantKey()];

        if ($method === 'tags') {
            $count = count($parameters);

            if ($count !== 1) {
                throw new \Exception("Method tags() takes exactly 1 argument. $count passed.");
            }

            $names = $parameters[0];
            $names = (array) $names; // cache()->tags('foo') https://laravel.com/docs/9.x/cache#removing-tagged-cache-items

            return $this->store()->tags(array_merge($tags, $names));
        }

        return $this->store()->tags($tags)->$method(...$parameters);
    }

    public function refreshStore(string|null $repository = null): void
    {
        Repository::macro('setStore', function ($store) {
            $this->store = $store;
        });

        $newStore = $this->resolve($repository ?? $this->getDefaultDriver())->getStore();

        $this->driver($repository)->setStore($newStore);
    }
}
