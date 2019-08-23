<?php

declare(strict_types=1);

namespace Stancl\Tenancy;

use Illuminate\Support\Facades\Route;
use Illuminate\Foundation\Support\Providers\RouteServiceProvider;

class TenantRouteServiceProvider extends RouteServiceProvider
{
    public function map()
    {
        if (! \in_array(request()->getHost(), $this->app['config']['tenancy.exempt_domains'] ?? [])
            && \file_exists(base_path('routes/tenant.php'))) {
            Route::middleware(['web', 'tenancy'])
                ->namespace($this->app['config']['tenant_route_namespace'] ?? 'App\Http\Controllers')
                ->group(base_path('routes/tenant.php'));
        }
    }
}
