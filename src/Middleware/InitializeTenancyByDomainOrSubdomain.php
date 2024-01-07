<?php

declare(strict_types=1);

namespace Stancl\Tenancy\Middleware;

use Closure;
use Exception;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Str;
use Stancl\Tenancy\Concerns\UsableWithEarlyIdentification;

class InitializeTenancyByDomainOrSubdomain extends InitializeTenancyBySubdomain
{
    use UsableWithEarlyIdentification;

    /** @return Response|mixed */
    public function handle(Request $request, Closure $next): mixed
    {
        if ($this->shouldBeSkipped(tenancy()->getRoute($request))) {
            return $next($request);
        }

        $domain = $this->getDomain($request);

        if ($this->isSubdomain($domain)) {
            $domain = $this->makeSubdomain($domain);

            if (is_object($domain) && $domain instanceof Exception) {
                $onFail = static::$onFail ?? function ($e) {
                    throw $e;
                };

                return $onFail($domain, $request, $next);
            }

            // If a Response instance was returned, we return it immediately.
            if (is_object($domain) && $domain instanceof Response) {
                return $domain;
            }
        }

        return $this->initializeTenancy(
            $request,
            $next,
            $domain
        );
    }

    protected function isSubdomain(string $hostname): bool
    {
        return Str::endsWith($hostname, config('tenancy.central_domains'));
    }
}
