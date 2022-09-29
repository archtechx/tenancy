<?php

declare(strict_types=1);

namespace Stancl\Tenancy\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class InitializeTenancyByDomainOrSubdomain
{
    /** @return \Illuminate\Http\Response|mixed */
    public function handle(Request $request, Closure $next): mixed
    {
        if ($this->isSubdomain($request->getHost())) {
            return app(InitializeTenancyBySubdomain::class)->handle($request, $next);
        } else {
            return app(InitializeTenancyByDomain::class)->handle($request, $next);
        }
    }

    protected function isSubdomain(string $hostname): bool
    {
        return Str::endsWith($hostname, config('tenancy.central_domains'));
    }
}
