<?php

declare(strict_types=1);

namespace Stancl\Tenancy\Concerns;

use Illuminate\Http\Request;

/**
 * Identification middleware has to implement this in order to make universal routes work with it,.
 */
interface UsableWithUniversalRoutes
{
    /**
     * Determine if the tenant is present in the incoming request.
     *
     * Because universal routes can be in any context (central/tenant),
     * we use this to determine the context. We can't just check for
     * the route's middleware to determine the route's context.
     *
     * For example, route '/foo' has the 'universal' and InitializeTenancyByRequestData middleware.
     * When visiting the route, we should determine the context by the presence of the tenant payload.
     * The context is tenant if the tenant parameter is present (e.g. '?tenant=foo'), otherwise the context is central.
     */
    public function requestHasTenant(Request $request): bool;
}
