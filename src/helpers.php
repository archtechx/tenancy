<?php

declare(strict_types=1);

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
    function tenant(string $key = null): mixed
    {
        if (! app()->bound(Tenant::class)) {
            return null;
        }

        if (is_null($key)) {
            return app(Tenant::class);
        }

        return optional(app(Tenant::class))->getAttribute($key) ?? null;
    }
}

if (! function_exists('tenant_asset')) {
    // todo docblock
    function tenant_asset(string|null $asset): string
    {
        return route('stancl.tenancy.asset', ['path' => $asset]);
    }
}

if (! function_exists('global_asset')) {
    function global_asset(string $asset) // todo types, also inside the globalUrl implementation
    {
        return app('globalUrl')->asset($asset);
    }
}

if (! function_exists('global_cache')) {
    function global_cache()
    {
        return app('globalCache');
    }
}

if (! function_exists('tenant_route')) {
    function tenant_route(string $domain, string $route, array $parameters = [], bool $absolute = true): string
    {
        // replace the first occurrence of the hostname fragment with $domain
        $url = route($route, $parameters, $absolute);
        $hostname = parse_url($url, PHP_URL_HOST);
        $position = strpos($url, $hostname);

        return substr_replace($url, $domain, $position, strlen($hostname));
    }
}
