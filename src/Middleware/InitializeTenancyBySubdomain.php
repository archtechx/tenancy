<?php

declare(strict_types=1);

namespace Stancl\Tenancy\Middleware;

use Closure;
use Exception;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Stancl\Tenancy\Concerns\UsableWithEarlyIdentification;
use Stancl\Tenancy\Exceptions\NotASubdomainException;
use Stancl\Tenancy\Resolvers\DomainTenantResolver;

class InitializeTenancyBySubdomain extends InitializeTenancyByDomain
{
    use UsableWithEarlyIdentification;

    /**
     * The index of the subdomain fragment in the hostname
     * split by `.`. 0 for first fragment, 1 if you prefix
     * your subdomain fragments with `www`.
     *
     * @var int
     */
    public static $subdomainIndex = 0;

    public static ?Closure $onFail = null;

    /** @return Response|mixed */
    public function handle(Request $request, Closure $next): mixed
    {
        if ($this->shouldBeSkipped(tenancy()->getRoute($request))) {
            // Allow accessing central route in kernel identification
            return $next($request);
        }

        $subdomain = $this->makeSubdomain($this->getDomain($request));

        if ($subdomain instanceof Exception) {
            $onFail = static::$onFail ?? function ($e) {
                throw $e;
            };

            return $onFail($subdomain, $request, $next);
        }

        // If a Response instance was returned, we return it immediately.
        if ($subdomain instanceof Response) {
            return $subdomain;
        }

        return $this->initializeTenancy(
            $request,
            $next,
            $subdomain
        );
    }

    /** @return string|Exception */
    protected function makeSubdomain(string $hostname)
    {
        $parts = explode('.', $hostname);

        $isIpAddress = count(array_filter($parts, 'is_numeric')) === count($parts);
        $isACentralDomain = in_array($hostname, config('tenancy.identification.central_domains'), true);
        $thirdPartyDomain = ! DomainTenantResolver::isSubdomain($hostname);

        if ($isACentralDomain || $isIpAddress || $thirdPartyDomain) {
            return new NotASubdomainException($hostname);
        }

        return $parts[static::$subdomainIndex];
    }
}
