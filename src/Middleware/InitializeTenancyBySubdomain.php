<?php

declare(strict_types=1);

namespace Stancl\Tenancy\Middleware;

use Closure;
use Illuminate\Http\Response;
use Stancl\Tenancy\Exceptions\NotASubdomainException;

class InitializeTenancyBySubdomain extends InitializeTenancyByDomain
{
    /** @var callable|null */
    public static $onInvalidSubdomain;

    /**
     * The index of the subdomain fragment in the hostname
     * split by `.`. 0 for first fragment, 1 if you prefix
     * your subdomain fragments with `www`.
     *
     * @var int
     */
    public static $subdomainIndex = 0;

    /**
     * Handle an incoming request.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  \Closure  $next
     * @return mixed
     */
    public function handle($request, Closure $next)
    {
        $subdomain = $this->makeSubdomain($request->getHost());

        // If a non-string, like a Response instance was returned
        // from makeSubdomain() - due to NotASubDomainException
        // being thrown, we abort by returning the value now.
        if (! is_string($subdomain)) {
            return $subdomain;
        }

        return $this->initializeTenancy(
            $request, $next, $subdomain
        );
    }

    /** @return string|Response|mixed */
    protected function makeSubdomain(string $hostname)
    {
        $parts = explode('.', $hostname);

        // If we're on localhost or an IP address, then we're not visiting a subdomain.
        if (in_array(count($parts), [1, 4])) {
            $handle = static::$onInvalidSubdomain ?? function ($e) {
                throw $e;
            };

            return $handle(new NotASubdomainException($hostname));
        }

        // todo should we verify that the subdomain belongs to one of our central domains?
        // if yes, then write a test for it.

        return $parts[static::$subdomainIndex];
    }
}
