<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Stancl\Tenancy\Controllers\TenantAssetController;

// todo make this work with path identification
Route::get('/tenancy/assets/{path?}', [TenantAssetController::class, 'asset'])
    ->where('path', '(.*)')
    ->name('stancl.tenancy.asset');
