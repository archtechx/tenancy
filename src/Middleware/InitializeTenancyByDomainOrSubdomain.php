<?php

declare(strict_types=1);

namespace Stancl\Tenancy\Middleware;

use Closure;
use Exception;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Stancl\Tenancy\Concerns\UsableWithEarlyIdentification;
use Stancl\Tenancy\Contracts\TenantCouldNotBeIdentifiedException;
use Stancl\Tenancy\Resolvers\DomainTenantResolver;

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
        $subdomain = null;

        if (DomainTenantResolver::isSubdomain($domain)) {
            $subdomain = $this->makeSubdomain($domain);

            if ($subdomain instanceof Exception) {
                $onFail = static::$onFail ?? function ($e) {
                    throw $e;
                };

                return $onFail($subdomain, $request, $next);
            }
        }

        try {
            $this->tenancy->initialize(
                $this->resolver->resolve($subdomain ?? $domain)
            );
        } catch (TenantCouldNotBeIdentifiedException $e) {
            if ($subdomain) {
                try {
                    $this->tenancy->initialize(
                        $this->resolver->resolve($domain)
                    );
                } catch (TenantCouldNotBeIdentifiedException $e) {
                    $onFail = static::$onFail ?? function ($e) {
                        throw $e;
                    };

                    return $onFail($e, $request, $next);
                }
            } else {
                $onFail = static::$onFail ?? function ($e) {
                    throw $e;
                };

                return $onFail($e, $request, $next);
            }
        }

        return $next($request);
    }
}
