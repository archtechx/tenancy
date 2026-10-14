<?php

declare(strict_types=1);

namespace Stancl\Tenancy\Overrides;

use Illuminate\Cache\CacheManager as BaseCacheManager;
use Illuminate\Cache\MemoizedStore;
use function Illuminate\Support\enum_value;

class CacheManager extends BaseCacheManager
{
    /**
     * Add tags and forward the call to the inner cache store.
     *
     * @param string $method
     * @param array $parameters
     */
    public function __call($method, $parameters)
    {
        $tags = $this->tenantTags();

        if ($method === 'tags') {
            $count = count($parameters);

            if ($count !== 1) {
                throw new \Exception("Method tags() takes exactly 1 argument. $count passed.");
            }

            $names = array_values($parameters)[0];
            $names = (array) $names; // cache()->tags('foo') https://laravel.com/docs/9.x/cache#removing-tagged-cache-items

            return $this->store()->tags(array_merge($tags, $names));
        }

        return $this->store()->tags($tags)->$method(...$parameters);
    }

    /**
     * Get a memoized cache driver instance that uses the tenant's tags.
     *
     * The memoized repository is bound per tenant, so memoized
     * values never leak between tenants or the central context.
     *
     * @param string|null $driver
     * @return \Illuminate\Contracts\Cache\Repository
     */
    public function memo($driver = null)
    {
        $driver = enum_value($driver) ?? $this->getDefaultDriver();

        $bindingKey = "cache.__memoized:{$driver}:tenant:" . tenant()->getTenantKey();

        $this->app->scopedIf($bindingKey, function () use ($driver) {
            return $this->repository(
                new MemoizedStore($driver, $this->store($driver)->tags($this->tenantTags())),
                ['events' => false]
            );
        });

        return $this->app->make($bindingKey);
    }

    /** @return string[] */
    protected function tenantTags(): array
    {
        return [config('tenancy.cache.tag_base') . tenant()->getTenantKey()];
    }
}
