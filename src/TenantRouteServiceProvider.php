<?php

declare(strict_types=1);

namespace Stancl\Tenancy;

use Illuminate\Foundation\Support\Providers\RouteServiceProvider;
use Illuminate\Support\Facades\Route;

class TenantRouteServiceProvider extends RouteServiceProvider
{
    public function map()
    {
        $this->app->booted(function () {
            if (file_exists(base_path('routes/tenant.php'))) {
                Route::middleware(['web', 'tenancy'])
                    ->namespace($this->app['config']['tenancy.tenant_route_namespace'] ?? 'App\Http\Controllers')
                    ->group(base_path('routes/tenant.php'));
            }
        });
    }
}
