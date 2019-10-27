<?php

declare(strict_types=1);

namespace Stancl\Tenancy\Middleware;

use Closure;
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
        if (request()->method() !== 'OPTIONS') {
            try {
                $this->parseTenant();
            } catch (TenantCouldNotBeIdentifiedException $e) {
                ($this->onFail)($e);
            }
        }

        return $next($request);
    }

    protected function parseTenant()
    {
        if (tenancy()->initialized) {
            return;
        }

        $tenant = null;
        if (request()->hasHeader('X-Tenant')) {
            $tenant = request()->header('X-Tenant');
        } elseif (request()->has('_tenant')) {
            $tenant = request()->get('_tenant');
        } elseif (! in_array(request()->getHost(), config('tenancy.exempt_domains', []), true)) {
            $tenant = explode('.', request()->getHost())[0];
        }

        if (! $tenant) {
            throw new TenantCouldNotBeIdentifiedException(request()->getHost());
        }

        tenancy()->initialize(tenancy()->find($tenant));
    }
}
