<?php

declare(strict_types=1);

namespace Stancl\Tenancy\Middleware;

use Closure;
use Illuminate\Foundation\Exceptions\RegisterErrorViewPaths;
use Illuminate\Foundation\Http\MaintenanceModeBypassCookie;
use Illuminate\Foundation\Http\Middleware\CheckForMaintenanceMode;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Throwable;

class CheckTenantForMaintenanceMode extends CheckForMaintenanceMode
{
    public function handle($request, Closure $next)
    {
        if (! tenant()) {
            // If there's no tenant, there's no tenant to check for maintenance mode.
            // Since tenant identification middleware has higher priority than this
            // middleware, a missing tenant would have already lead to request termination.
            // (And even if priority were misconfigured, the request would simply get
            // terminated *after* this middleware.)
            // Therefore, we are likely on a universal route, in central context.
            return $next($request);
        }

        if (tenant('maintenance_mode')) {
            $data = tenant('maintenance_mode');

            $data['secret'] = filled($data['secret'] ?? null) ? $data['secret'] : null;

            $secret = $request->query('maintenance_secret');

            if (is_string($data['secret']) && is_string($secret) && hash_equals($data['secret'], $secret)) {
                return redirect($request->fullUrlWithoutQuery('maintenance_secret'))
                    ->withCookie(MaintenanceModeBypassCookie::create($data['secret']));
            }

            if ($this->hasValidBypassCookie($request, $data) ||
                $this->inExceptArray($request)) {
                return $next($request);
            }

            if (isset($data['redirect']) && ! $request->expectsJson()) {
                $path = $data['redirect'] === '/'
                    ? $data['redirect']
                    : trim($data['redirect'], '/');

                if ($request->path() !== $path) {
                    return redirect($path);
                }
            }

            if (isset($data['render']) && ! $request->expectsJson()) {
                try {
                    (new RegisterErrorViewPaths)();

                    return response(
                        view($data['render'], ['retryAfter' => $data['retry'] ?? null])->render(),
                        (int) ($data['status'] ?? 503),
                        $this->getHeaders($data)
                    );
                } catch (Throwable $e) {
                    // Fall back to the default maintenance mode response
                    report($e);
                }
            }

            throw new HttpException(
                (int) ($data['status'] ?? 503),
                'Service Unavailable',
                null,
                $this->getHeaders($data)
            );
        }

        return $next($request);
    }

    public function getExcludedPaths()
    {
        return array_merge(parent::getExcludedPaths(), tenant('maintenance_mode')['except'] ?? []);
    }
}
