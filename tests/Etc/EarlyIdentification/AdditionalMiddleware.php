<?php

namespace Stancl\Tenancy\Tests\Etc\EarlyIdentification;

use Closure;
use Illuminate\Http\Request;

class AdditionalMiddleware
{
    public function handle(Request $request, Closure $next): mixed
    {
        app()->instance('additionalMiddlewareRunsInTenantContext', tenancy()->initialized);

        return $next($request);
    }
}
