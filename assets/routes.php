<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;

if (config('tenancy.filesystem.serve_tenant_assets', true)) {

    Route::get('/tenancy/assets/{path?}', 'Stancl\Tenancy\Controllers\TenantAssetsController@asset')
        ->where('path', '(.*)')
        ->name('stancl.tenancy.asset');
}
