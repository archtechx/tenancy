<?php

Route::get('/tenancy/assets/{path}', 'Stancl\Tenancy\Controllers\TenantAssetController@asset')
    ->where('path', '(.*)')
    ->name('stancl.tenancy.asset');
