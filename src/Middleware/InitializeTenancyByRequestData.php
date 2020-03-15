<?php

declare(strict_types=1);

namespace Stancl\Tenancy\Middleware;

use Closure;
use Illuminate\Http\Request;
use Stancl\Tenancy\Exceptions\TenantCouldNotBeIdentifiedException;

class InitializeTenancyByRequestData
{
    /** @var callable */
    protected $onFail;

    public function __construct(callable $onFail = null)
    {
        $this->onFail = $onFail ?? function ($e) {
            throw $e;
        };
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
        if ($request->method() !== 'OPTIONS') {
            try {
                $this->parseTenant($request);
            } catch (TenantCouldNotBeIdentifiedException $e) {
                ($this->onFail)($e);
            }
        }

        return $next($request);
    }

    protected function parseTenant(Request $request)
    {
        if (tenancy()->initialized) {
            return;
        }

        $header = config('tenancy.identification.header');
        $query = config('tenancy.identification.query_parameter');

        $tenant = null;
        if ($request->hasHeader($header)) {
            $tenant = $request->header($header);
        } elseif ($request->has($query)) {
            $tenant = $request->get($query);
        } elseif (! in_array($request->getHost(), config('tenancy.exempt_domains', []), true)) {
            $tenant = explode('.', $request->getHost())[0];
        }

        if (! $tenant) {
            throw new TenantCouldNotBeIdentifiedException($request->getHost());
        }

        tenancy()->initialize(tenancy()->find($tenant));
    }
}
