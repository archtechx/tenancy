<?php

declare(strict_types=1);

namespace Stancl\Tenancy\Middleware;

use Closure;
use Illuminate\Foundation\Http\Middleware\CheckForMaintenanceMode;
use Symfony\Component\HttpKernel\Exception\HttpException;

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

            if (isset($data['secret']) && $request->path() === $data['secret']) {
                return $this->bypassResponse($data['secret']);
            }

            if ($this->hasValidBypassCookie($request, $data) ||
                $this->inExceptArray($request)) {
                return $next($request);
            }

            if (isset($data['redirect'])) {
                $path = $data['redirect'] === '/'
                    ? $data['redirect']
                    : trim($data['redirect'], '/');

                if ($request->path() !== $path) {
                    return redirect($path);
                }
            }

            if (isset($data['template'])) {
                return response(
                    $data['template'],
                    (int) ($data['status'] ?? 503),
                    $this->getHeaders($data)
                );
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
}
