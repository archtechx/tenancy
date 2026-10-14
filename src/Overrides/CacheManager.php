<?php

declare(strict_types=1);

namespace Stancl\Tenancy\Overrides;

use ArrayObject;
use Illuminate\Cache\CacheManager as BaseCacheManager;
use function Illuminate\Support\enum_value;
use Mockery;
use Mockery\LegacyMockInterface;

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
     * Memoized repositories are kept per driver and tenant in a single scoped
     * binding, so memoized values never leak between tenants or the central
     * context, and are reset along with other scoped instances.
     *
     * @param string|null $driver
     * @return \Illuminate\Contracts\Cache\Repository
     */
    public function memo($driver = null)
    {
        $driver = enum_value($driver) ?? $this->getDefaultDriver();

        $this->app->scopedIf('cache.__memoized:tenancy', static fn () => new ArrayObject);

        /** @var ArrayObject<string, \Illuminate\Contracts\Cache\Repository> $repositories */
        $repositories = $this->app->make('cache.__memoized:tenancy');

        $key = $driver . ':' . tenant()->getTenantKey();

        if (! isset($repositories[$key])) {
            $isSpy = isset($this->app['cache']) && $this->app['cache'] instanceof LegacyMockInterface;

            $repository = $this->repository(
                new TenantMemoizedStore($driver, $this->store($driver)->tags($this->tenantTags())),
                ['events' => false]
            );

            if ($isSpy) {
                /** @var \Illuminate\Contracts\Cache\Repository $repository */
                $repository = Mockery::spy($repository);
            }

            $repositories[$key] = $repository;
        }

        return $repositories[$key];
    }

    /** @return string[] */
    protected function tenantTags(): array
    {
        return [config('tenancy.cache.tag_base') . tenant()->getTenantKey()];
    }
}
