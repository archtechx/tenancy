<?php

declare(strict_types=1);

// if app.asset_url is set, suffix it
// if it's not set, use this controller?
Route::get('/tenancy/assets/{path}', 'Stancl\Tenancy\Controllers\TenantAssetsController@asset')
    ->where('path', '(.*)')
    ->name('stancl.tenancy.asset');
