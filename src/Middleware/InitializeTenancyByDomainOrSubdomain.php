<?php

declare(strict_types=1);

namespace Stancl\Tenancy\Middleware;

use Closure;
use Exception;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Stancl\Tenancy\Concerns\UsableWithEarlyIdentification;
use Stancl\Tenancy\Contracts\TenantCouldNotBeIdentifiedException;
use Stancl\Tenancy\Exceptions\TenantIsPendingException;
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

        $onFail = static::$onFail ?? function ($e) {
            throw $e;
        };

        $domain = $this->getDomain($request);
        $subdomain = null;

        if (DomainTenantResolver::isSubdomain($domain)) {
            $subdomain = $this->makeSubdomain($domain);

            if ($subdomain instanceof Exception) {
                return $onFail($subdomain, $request, $next);
            }
        }

        try {
            $this->tenancy->initialize(
                $this->resolveTenant($subdomain ?? $domain)
            );
        } catch (TenantIsPendingException $e) {
            // Catch this subtype of TenantCouldNotBeIdentifiedException first to fail early
            return $onFail($e, $request, $next);
        } catch (TenantCouldNotBeIdentifiedException $e) {
            if ($subdomain !== null) {
                try {
                    $this->tenancy->initialize(
                        $this->resolveTenant($domain)
                    );
                } catch (TenantCouldNotBeIdentifiedException $e) {
                    return $onFail($e, $request, $next);
                }
            } else {
                return $onFail($e, $request, $next);
            }
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
