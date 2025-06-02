<?php

declare(strict_types=1);

namespace Stancl\Tenancy\Middleware;

use Closure;
use Illuminate\Http\Request;
use Stancl\Tenancy\Exceptions\TenancyNotInitializedException;

class ScopeSessions
{
    public static string $tenantIdKey = '_tenant_id';

    /** @var Closure(Request): mixed */
    public static Closure|null $onFail = null;

    /** @return \Illuminate\Http\Response|mixed */
    public function handle(Request $request, Closure $next): mixed
    {
        if (! tenancy()->initialized) {
            if (tenancy()->routeIsUniversal(tenancy()->getRoute($request))) {
                return $next($request);
            }

            throw new TenancyNotInitializedException('Tenancy needs to be initialized before the session scoping middleware is executed');
        }

        if (! $request->session()->has(static::$tenantIdKey)) {
            $request->session()->put(static::$tenantIdKey, tenant()->getTenantKey());
        } else {
            if ($request->session()->get(static::$tenantIdKey) !== tenant()->getTenantKey()) {
                return static::$onFail !== null
                    ? (static::$onFail)($request)
                    : abort(403);
            }
        }

        return $next($request);
    }
}
