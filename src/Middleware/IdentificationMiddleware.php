<?php

declare(strict_types=1);

namespace Stancl\Tenancy\Middleware;

use Closure;
use Illuminate\Http\Request;
use Stancl\Tenancy\Contracts\TenantCouldNotBeIdentifiedException;
use Stancl\Tenancy\Contracts\TenantResolver;
use Stancl\Tenancy\Tenancy;

/**
 * @property Tenancy $tenancy
 * @property TenantResolver $resolver
 */
abstract class IdentificationMiddleware
{
    public static ?Closure $onFail = null;

    /**
     * Should Tenancy be ended before returning the response.
     *
     * This can be useful in tests if it's unergonomic that the test stays in the
     * tenant context after making a request to a tenant page.
     */
    public static bool $terminateTenancy = false;

    /** @return \Illuminate\Http\Response|mixed */
    public function initializeTenancy(Request $request, Closure $next, mixed ...$resolverArguments): mixed
    {
        try {
            $this->tenancy->initialize(
                $this->resolver->resolve(...$resolverArguments)
            );
        } catch (TenantCouldNotBeIdentifiedException $e) {
            $onFail = static::$onFail ?? function ($e) {
                throw $e;
            };

            return $onFail($e, $request, $next);
        }

        try {
            return $next($request);
        } finally {
            if (static::$terminateTenancy) {
                $this->tenancy->end();
            }
        }
    }
}
