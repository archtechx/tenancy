<?php

declare(strict_types=1);

Route::middleware(['tenancy'])->group(function () {
    Route::get('/tenancy/assets/{path?}', 'Stancl\Tenancy\Controllers\TenantAssetsController@asset')
        ->where('path', '(.*)')
        ->name('stancl.tenancy.asset');
});
