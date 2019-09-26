<?php

declare(strict_types=1);

namespace Stancl\Tenancy\Middleware;

use Closure;

class InitializeTenancy
{
    /**
     * @var \Closure
     */
    protected $onFail;

    public function __construct(Closure $onFail = null)
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
        try {
            tenancy()->init();
        } catch (\Exception $e) {
            ($this->onFail)($e);
        }

        return $next($request);
    }
}
