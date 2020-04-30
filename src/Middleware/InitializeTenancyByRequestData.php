<?php

declare(strict_types=1);

namespace Stancl\Tenancy\Middleware;

use Closure;
use Illuminate\Http\Request;
use Stancl\Tenancy\Exceptions\TenantCouldNotBeIdentifiedException;

class InitializeTenancyByRequestData
{
    /** @var string|null */
    protected $header;

    /** @var string|null */
    protected $queryParameter;

    /** @var callable */
    protected $onFail;

    public function __construct(?string $header = 'X-Tenant', ?string $queryParameter = 'tenant', callable $onFail = null)
    {
        $this->header = $header;
        $this->queryParameter = $queryParameter;
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
                $this->initializeTenancy($request);
            } catch (TenantCouldNotBeIdentifiedException $e) {
                return ($this->onFail)($e, $request, $next);
            }
        }

        return $next($request);
    }

    protected function initializeTenancy(Request $request)
    {
        if (tenancy()->initialized) {
            return;
        }

        $tenant = null;
        if ($this->header && $request->hasHeader($this->header)) {
            $tenant = $request->header($this->header);
        } elseif ($this->queryParameter && $request->has($this->queryParameter)) {
            $tenant = $request->get($this->queryParameter);
        }

        if (! $tenant) {
            throw new TenantCouldNotBeIdentifiedException($request->getHost());
        }

        tenancy()->initialize(tenancy()->find($tenant));
    }
}
