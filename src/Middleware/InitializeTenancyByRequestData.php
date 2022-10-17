<?php

declare(strict_types=1);

namespace Stancl\Tenancy\Middleware;

use Closure;
use Illuminate\Http\Request;
use Stancl\Tenancy\Resolvers\RequestDataTenantResolver;
use Stancl\Tenancy\Tenancy;

class InitializeTenancyByRequestData extends IdentificationMiddleware
{
    public static string $header = 'X-Tenant';
    public static string $queryParameter = 'tenant';
    public static ?Closure $onFail = null;

    public function __construct(
        protected Tenancy $tenancy,
        protected RequestDataTenantResolver $resolver,
    ) {
    }

    /** @return \Illuminate\Http\Response|mixed */
    public function handle(Request $request, Closure $next): mixed
    {
        if ($request->method() !== 'OPTIONS') {
            return $this->initializeTenancy($request, $next, $this->getPayload($request));
        }

        return $next($request);
    }

    protected function getPayload(Request $request): ?string
    {
        if (static::$header && $request->hasHeader(static::$header)) {
            return $request->header(static::$header);
        }

        if (static::$queryParameter && $request->has(static::$queryParameter)) {
            return $request->get(static::$queryParameter);
        }

        if (static::$header && $request->hasCookie(static::$header)) {
            return $request->cookie(static::$header);
        }

        return null;
    }
}
