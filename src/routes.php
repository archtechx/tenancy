<?php

declare(strict_types=1);

Route::get('/tenancy/assets/{path}', 'Stancl\Tenancy\Controllers\TenantAssetsController@asset')
    ->where('path', '(.*)')
    ->name('stancl.tenancy.asset');
