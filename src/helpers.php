<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Broadcast;
use Stancl\Tenancy\Contracts\Tenant;
use Stancl\Tenancy\Tenancy;

if (! function_exists('tenancy')) {
    /** @return Tenancy */
    function tenancy()
    {
        return app(Tenancy::class);
    }
}

if (! function_exists('tenant')) {
    /**
     * Get the current tenant or a key from the current tenant's properties.
     *
     * @return Tenant|null|mixed
     */
    function tenant(?string $key = null): mixed
    {
        if (! app()->bound(Tenant::class)) {
            return null;
        }

        if (is_null($key)) {
            return app(Tenant::class);
        }

        // @phpstan-ignore-next-line nullsafe.neverNull
        return app(Tenant::class)?->getAttribute($key);
    }
}

if (! function_exists('tenant_asset')) {
    /**
     * Generate a URL to an asset in tenant storage.
     *
     * If app.asset_url is set, this helper suffixes that URL before appending the asset path.
     * If it is not set, the stancl.tenancy.asset route is used.
     */
    function tenant_asset(string|null $asset): string
    {
        if ($assetUrl = config('app.asset_url')) {
            $assetUrl = str($assetUrl)->rtrim('/')->append('/');

            if (tenant()) {
                $assetUrl .= config('tenancy.filesystem.suffix_base') . tenant()->getTenantKey();
            }

            return $assetUrl . $asset;
        }

        return route('stancl.tenancy.asset', ['path' => $asset]);
    }
}

if (! function_exists('global_asset')) {
    function global_asset(string $asset): string
    {
        return app('globalUrl')->asset($asset);
    }
}

if (! function_exists('global_cache')) {
    /**
     * Get / set the specified cache value in the global cache store.
     *
     * If an array is passed, we'll assume you want to put to the cache.
     *
     * @param  dynamic  key|key,default|data,expiration|null
     * @return mixed|Illuminate\Cache\CacheManager
     *
     * @throws InvalidArgumentException
     */
    function global_cache(): mixed
    {
        $arguments = func_get_args();

        if (empty($arguments)) {
            return app('globalCache');
        }

        if (is_string($arguments[0])) {
            return app('globalCache')->get(...$arguments);
        }

        if (! is_array($arguments[0])) {
            throw new InvalidArgumentException(
                'When setting a value in the cache, you must pass an array of key / value pairs.'
            );
        }

        return app('globalCache')->put(key($arguments[0]), reset($arguments[0]), $arguments[1] ?? null);
    }
}

if (! function_exists('tenant_route')) {
    function tenant_route(string $domain, string $route, array $parameters = []): string
    {
        $url = route($route, $parameters, absolute: true);

        /**
         * The original hostname in the generated route.
         *
         * @var string $hostname
         */
        $hostname = parse_url($url, PHP_URL_HOST);

        return (string) str($url)->replace($hostname, $domain);
    }
}

if (! function_exists('tenant_channel')) {
    function tenant_channel(string $channelName, Closure $callback, array $options = []): void
    {
        // Register '{tenant}.channelName'
        Broadcast::channel('{tenant}.' . $channelName, $callback, $options);
    }
}

if (! function_exists('global_channel')) {
    function global_channel(string $channelName, Closure $callback, array $options = []): void
    {
        // Register 'global__channelName'
        // Global channels are available in both the central and tenant contexts
        Broadcast::channel('global__' . $channelName, $callback, $options);
    }
}
