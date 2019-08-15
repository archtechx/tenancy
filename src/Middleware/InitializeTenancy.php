<?php

namespace Stancl\Tenancy\Middleware;

use Closure;
use Laravel\Telescope\Telescope;

class InitializeTenancy
{
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

        if (class_exists(Telescope::class)) {
            $original_callback = Telescope::tagUsing;

            Telescope::tag(function (\Laravel\Telescope\IncomingEntry $entry) use ($original_callback) {
                $tags = [];
                if (tenancy()->initialized) {
                    $tags = ['tenant:' . tenant('uuid')];
                }

                return array_merge($original_callback($entry), $tags);
            });
        }

        return $next($request);
    }
}
