<?php

declare(strict_types=1);

namespace Stancl\Tenancy\Middleware;

use Stancl\Tenancy\Contracts\TenantCouldNotBeIdentifiedException;
use Stancl\Tenancy\Contracts\TenantResolver;
use Stancl\Tenancy\Resolvers\CachedTenantResolver;
use Stancl\Tenancy\Tenancy;

abstract class IdentificationMiddleware
{
    /** @var callable */
    public static $onFail;

    /** @var bool */
    public static $shouldCache = false;

    /** @var int */
    public static $cacheTTL = 3600; // seconds

    /** @var string|null */
    public static $cacheStore = null; // default

    /** @var Tenancy */
    protected $tenancy;

    /** @var TenantResolver */
    protected $resolver;

    public function initializeTenancy($request, $next, ...$resolverArguments)
    {
        try {
            if (static::$shouldCache) {
                app(CachedTenantResolver::class)->resolve(
                    get_class($this->resolver), $resolverArguments, static::$cacheTTL, static::$cacheStore
                );
            } else {
                $this->tenancy->initialize(
                    $this->resolver->resolve(...$resolverArguments)
                ); 
            }
        } catch (TenantCouldNotBeIdentifiedException $e) {
            $onFail = static::$onFail ?? function ($e) {
                throw $e;
            };

            return $onFail($e, $request, $next);
        }

        return $next($request);
    }
}
