<?php

declare(strict_types=1);

namespace Stancl\Tenancy\Middleware;

use Closure;
use Illuminate\Http\Request;
use Stancl\Tenancy\Resolvers\DomainTenantResolver;
use Stancl\Tenancy\Tenancy;

class InitializeTenancyByDomain extends IdentificationMiddleware
{
    public static ?Closure $onFail = null;

    public function __construct(
        protected Tenancy $tenancy,
        protected DomainTenantResolver $resolver,
    ) {
    }

    /** @return \Illuminate\Http\Response|mixed */
    public function handle(Request $request, Closure $next): mixed
    {
        return $this->initializeTenancy(
            $request,
            $next,
            $request->getHost()
        );
    }
}
