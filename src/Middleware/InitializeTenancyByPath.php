<?php

namespace Stancl\Tenancy\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Stancl\Tenancy\Resolvers\PathTenantResolver;
use Stancl\Tenancy\Tenancy;

class InitializeTenancyByPath extends IdentificationMiddleware
{
    /** @var Tenancy */
    protected $tenancy;

    /** @var PathTenantResolver */
    protected $resolver;

    public function __construct(Tenancy $tenancy, PathTenantResolver $resolver)
    {
        $this->tenancy = $tenancy;
        $this->resolver = $resolver;
    }

    public function handle(Request $request, Closure $next)
    {
        /** @var Route $route */
        $route = $request->route();

        // todo test the behavior described by the comment
        // Only initialize tenancy if tenant is the first parameter
        // We don't want to initialize tenancy if the tenant is
        // simply injected into some route controller action.
        if ($route->parameterNames()[0] === 'tenant') {
            return $this->initializeTenancy(
                $request, $next, $route
            );
        } // todo else case should probably throw exception about malformed route? or do we just leave that as the developer's responsibility?

        return $next($request);
    }
}