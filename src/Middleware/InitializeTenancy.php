<?php

namespace Stancl\Tenancy\Middleware;

use Closure;

class InitializeTenancy
{
    public function __construct(Closure $onFail = null)
    {
        $this->onFail = $onFail ?: function ($e) { throw $e; };
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
            // Pass the exception to the onFail function if it takes any parameters.
            $callback = $this->onFail;
            if ((new \ReflectionFunction($callback))->getNumberOfParameters() > 0) {
                $callback($e);
            } else {
                $callback();
            }
        }

        return $next($request);
    }
}
