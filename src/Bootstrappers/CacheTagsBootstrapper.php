<?php

declare(strict_types=1);

namespace Stancl\Tenancy\Bootstrappers;

use Illuminate\Cache\CacheManager;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\Facades\Cache;
use Stancl\Tenancy\Contracts\TenancyBootstrapper;
use Stancl\Tenancy\Contracts\Tenant;

/**
 * Separate tenant cache using tagging.
 *
 * This is the legacy approach. Some things, like dependency injection, won't work properly with this bootstrapper.
 * CacheTenancyBootstrapper is the recommended bootstrapper for cache separation.
 */
class CacheTagsBootstrapper implements TenancyBootstrapper
{
    protected ?CacheManager $originalCache = null;
    public static string $cacheManagerWithTags = \Stancl\Tenancy\Overrides\CacheManager::class;

    public function __construct(
        protected Application $app
    ) {}

    public function bootstrap(Tenant $tenant): void
    {
        $this->resetFacadeCache();

        $this->originalCache ??= $this->app['cache'];
        $this->app->extend('cache', function () {
            return new static::$cacheManagerWithTags($this->app);
        });
    }

    public function revert(): void
    {
        $this->resetFacadeCache();

        $this->app->extend('cache', function () {
            return $this->originalCache;
        });

        $this->originalCache = null;
    }

    /**
     * This wouldn't be necessary, but is needed when a call to the
     * facade has been made prior to bootstrapping tenancy. The
     * facade has its own cache, separate from the container.
     */
    public function resetFacadeCache(): void
    {
        Cache::clearResolvedInstances();
    }
}
