<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Stancl\Tenancy\PathIdentificationManager;
use Stancl\Tenancy\Controllers\TenantAssetController;

Route::get('/tenancy/assets/{path?}', TenantAssetController::class)
    ->where('path', '(.*)')
    ->middleware(config('tenancy.identification.default_middleware')) // todo@features Use tenancy()->defaultMiddleware() after merging #1021
    ->name('stancl.tenancy.asset');

Route::prefix('/{' . PathIdentificationManager::getTenantParameterName() . '}/')->get('/tenancy/assets/{path?}', TenantAssetController::class)
    ->where('path', '(.*)')
    ->middleware('tenant')
    ->name(PathIdentificationManager::getTenantRouteNamePrefix() . 'stancl.tenancy.asset');
