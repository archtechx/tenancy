<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Stancl\Tenancy\Controllers\TenantAssetController;
use Stancl\Tenancy\Resolvers\PathTenantResolver;

Route::get('/tenancy/assets/{path?}', TenantAssetController::class)
    ->where('path', '(.*)')
    ->name('stancl.tenancy.asset');

Route::prefix('/{' . PathTenantResolver::tenantParameterName() . '}/')->get('/tenancy/assets/{path?}', TenantAssetController::class)
    ->where('path', '(.*)')
    ->middleware('tenant')
    ->name(PathTenantResolver::tenantRouteNamePrefix() . 'stancl.tenancy.asset');
