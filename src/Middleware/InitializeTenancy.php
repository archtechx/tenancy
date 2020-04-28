<?php

declare(strict_types=1);

namespace Stancl\Tenancy\Middleware;

use Closure;
use Stancl\Tenancy\Exceptions\TenantCouldNotBeIdentifiedException;

class InitializeTenancy
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
        if (tenancy()->initialized) {
            return $next($request);
        }

        if (! in_array($request->getHost(), config('tenancy.exempt_domains', []), true)) {
            try {
                tenancy()->init($request->getHost());
            } catch (TenantCouldNotBeIdentifiedException $e) {
                return ($this->onFail)($e, $request, $next);
            }
        }

        return $next($request);
    }
}
