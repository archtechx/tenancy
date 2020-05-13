<?php

use Stancl\Tenancy\Middleware\InitializeTenancyByDomain;

/*
|--------------------------------------------------------------------------
| Tenant Routes
|--------------------------------------------------------------------------
|
| Here you can register the tenant routes for your application.
| These routes are loaded by the TenantRouteServiceProvider
| with the namespace configured in your tenancy config.
|
| Feel free to customize them however you want. Good luck!
|
*/

Route::group([
    'middleware' => ['web', InitializeTenancyByDomain::class],
    'prefix' => '/app',
], function () {
    Route::get('/', function () {
        return 'This is your multi-tenant application. The id of the current tenant is ' . tenant('id');
    });
});
