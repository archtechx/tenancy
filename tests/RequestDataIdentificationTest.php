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
    InitializeTenancyByRequestData::$cookie = 'X-Tenant';
    InitializeTenancyByRequestData::$queryParameter = 'tenant';
});

test('header identification works', function () {
    InitializeTenancyByRequestData::$header = 'X-Tenant';
    $tenant = Tenant::create();

    $this
        ->withoutExceptionHandling()
        ->withHeader('X-Tenant', $tenant->id)
        ->get('test')
        ->assertSee($tenant->id);
});

test('query parameter identification works', function () {
    InitializeTenancyByRequestData::$queryParameter = 'tenant';

    $tenant = Tenant::create();

    $this
        ->withoutExceptionHandling()
        ->get('test?tenant=' . $tenant->id)
        ->assertSee($tenant->id);
});

test('cookie identification works', function () {
    InitializeTenancyByRequestData::$cookie = 'X-Tenant';
    $tenant = Tenant::create();

    $this
        ->withoutExceptionHandling()
        ->withUnencryptedCookie('X-Tenant', $tenant->id)
        ->get('test',)
        ->assertSee($tenant->id);
});
