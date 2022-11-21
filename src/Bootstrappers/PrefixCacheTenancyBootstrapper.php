<?php

declare(strict_types=1);

namespace Stancl\Tenancy\Bootstrappers;

use Illuminate\Cache\Repository;
use Illuminate\Support\Facades\Cache;
use Stancl\Tenancy\Contracts\TenancyBootstrapper;
use Stancl\Tenancy\Contracts\Tenant;

class PrefixCacheTenancyBootstrapper implements TenancyBootstrapper
{
    protected null|string $originalPrefix = null;
    protected string $storeName;

    public function bootstrap(Tenant $tenant): void
    {
        $this->originalPrefix = config('cache.prefix');
        $this->storeName = config('cache.default');

        $this->setCachePrefix('tenant_id_' . $tenant->id);
    }

    public function revert(): void
    {
        if ($this->originalPrefix) {
            $this->setCachePrefix($this->originalPrefix);
            $this->originalPrefix = null;
        }
    }

    protected function setCachePrefix(string $prefix): void
    {
        config()->set('cache.prefix', $prefix);

        app('cache')->forgetDriver($this->storeName);

        // This is important because the `CacheManager` will have the `$app['config']` array cached
        // with old prefixes on the `cache` instance. Simply calling `forgetDriver` only removes
        // the `$store` but doesn't update the `$app['config']`.
        app()->forgetInstance('cache');

        //This is important because the Cache Repository is using an old version of the CacheManager
        app()->forgetInstance('cache.store');

        // Forget the cache repository in the container
        app()->forgetInstance(Repository::class);

        Cache::clearResolvedInstances();
    }
}
