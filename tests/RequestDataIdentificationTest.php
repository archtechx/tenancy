<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Stancl\Tenancy\Middleware\InitializeTenancyByRequestData;
use Stancl\Tenancy\Tests\Etc\Tenant;

beforeEach(function () {
    config([
        'tenancy.central_domains' => [
            'localhost',
        ],
    ]);

    Route::middleware(InitializeTenancyByRequestData::class)->get('/test', function () {
        return 'Tenant id: ' . tenant('id');
    });
});

afterEach(function () {
    InitializeTenancyByRequestData::$header = 'X-Tenant';
    InitializeTenancyByRequestData::$queryParameter = 'tenant';
});

test('header identification works', function () {
    InitializeTenancyByRequestData::$header = 'X-Tenant';
    $tenant = Tenant::create();
    $tenant2 = Tenant::create();

    $this
        ->withoutExceptionHandling()
        ->get('test', [
            'X-Tenant' => $tenant->id,
        ])
        ->assertSee($tenant->id);
});

test('query parameter identification works', function () {
    InitializeTenancyByRequestData::$header = null;
    InitializeTenancyByRequestData::$queryParameter = 'tenant';

    $tenant = Tenant::create();
    $tenant2 = Tenant::create();

    $this
        ->withoutExceptionHandling()
        ->get('test?tenant=' . $tenant->id)
        ->assertSee($tenant->id);
});
