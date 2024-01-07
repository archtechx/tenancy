<?php

declare(strict_types=1);

namespace Stancl\Tenancy\Middleware;

use Closure;
use Illuminate\Http\Request;
use Stancl\Tenancy\Concerns\UsableWithEarlyIdentification;
use Stancl\Tenancy\Concerns\UsableWithUniversalRoutes;
use Stancl\Tenancy\Resolvers\DomainTenantResolver;
use Stancl\Tenancy\Tenancy;

class InitializeTenancyByDomain extends IdentificationMiddleware implements UsableWithUniversalRoutes
{
    use UsableWithEarlyIdentification;

    public static ?Closure $onFail = null;

    public function __construct(
        protected Tenancy $tenancy,
        protected DomainTenantResolver $resolver,
    ) {
    }

    /** @return \Illuminate\Http\Response|mixed */
    public function handle(Request $request, Closure $next): mixed
    {
        if ($this->shouldBeSkipped(tenancy()->getRoute($request))) {
            // Allow accessing central route in kernel identification
            return $next($request);
        }

        $domain = $this->getDomain($request);

        return $this->initializeTenancy(
            $request,
            $next,
            $domain
        );
    }

    /**
     * Domain identification request has a tenant if it's
     * not hitting a domain specifically defined as central in the config.
     */
    public function requestHasTenant(Request $request): bool
    {
        return ! in_array($this->getDomain($request), config('tenancy.central_domains'));
    }

    public function getDomain(Request $request): string
    {
        return $request->getHost();
    }
}
