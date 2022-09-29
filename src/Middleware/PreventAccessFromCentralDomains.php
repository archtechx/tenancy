<?php

declare(strict_types=1);

namespace Stancl\Tenancy\Middleware;

use Closure;
use Illuminate\Http\Request;

class PreventAccessFromCentralDomains
{
    /**
     * Set this property if you want to customize the on-fail behavior.
     */
    public static ?Closure $abortRequest;

    /** @return \Illuminate\Http\Response|mixed */
    public function handle(Request $request, Closure $next): mixed
    {
        if (in_array($request->getHost(), config('tenancy.central_domains'))) {
            $abortRequest = static::$abortRequest ?? function () {
                abort(404);
            };

            return $abortRequest($request, $next);
        }

        return $next($request);
    }
}
