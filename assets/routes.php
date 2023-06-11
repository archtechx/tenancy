<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;

Route::get('/tenancy/assets/{path?}', 'Stancl\Tenancy\Controllers\TenantAssetsController@asset')
    ->where('path', '(.*)')
    ->name('stancl.tenancy.asset');

Route::get('/{tenant}/assets/{path?}', 'Stancl\Tenancy\Controllers\TenantAssetsController@assetWithPath')
    ->where('path', '(.*)')
    ->name('stancl.tenancy.asset.path');
