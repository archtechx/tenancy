<?php

declare(strict_types=1);

namespace Stancl\Tenancy\Middleware;

use Closure;
use Illuminate\Http\Request;
use Stancl\Tenancy\Tenancy;
use Stancl\Tenancy\Resolvers\RequestOriginTenantResolver;

class InitializeTenancyByRequestOrigin extends IdentificationMiddleware
{
	/** @var callable|null */
    public static $onFail;

    /** @var Tenancy */
    protected $tenancy;

    /** @var TenantResolver */
    protected $resolver;

    public function __construct(Tenancy $tenancy, RequestOriginTenantResolver $resolver)
    {
        $this->tenancy = $tenancy;
        $this->resolver = $resolver;
    }

    /**
     * Handle an incoming request.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  \Closure  $next
     * @return mixed
     */
    public function handle($request, Closure $next)
    {
	    return $this->initializeTenancy($request, $next, $this->getPayload($request));
    }

	protected function getPayload(Request $request): ?string
	{
		$tenant = null;
		if ($request->hasHeader('origin')) {
			$parts = parse_url($request->headers->get('origin'));
			$tenant = optional($parts)['host'];
			if (array_key_exists('port', $parts) && $tenant) {
				$tenant .= ":{$parts['port']}";
			}
		}

		return $tenant;
	}
}
