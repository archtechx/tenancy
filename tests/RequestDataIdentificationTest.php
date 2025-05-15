<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Stancl\Tenancy\Exceptions\TenantCouldNotBeIdentifiedByRequestDataException;
use Stancl\Tenancy\Middleware\InitializeTenancyByRequestData;
use Stancl\Tenancy\Tests\Etc\Tenant;
use function Stancl\Tenancy\Tests\pest;

beforeEach(function () {
    config([
        'tenancy.identification.central_domains' => [
            'localhost',
        ],
    ]);

    InitializeTenancyByRequestData::$header = 'X-Tenant';
    InitializeTenancyByRequestData::$cookie = 'X-Tenant';
    InitializeTenancyByRequestData::$queryParameter = 'tenant';

    Route::middleware(['tenant', InitializeTenancyByRequestData::class])->get('/test', function () {
        return 'Tenant id: ' . tenant('id');
    });
});

test('header identification works', function () {
    $tenant = Tenant::create();

    $this
        ->withoutExceptionHandling()
        ->withHeader('X-Tenant', $tenant->id)
        ->get('test')
        ->assertSee($tenant->id);
});

test('query parameter identification works', function () {
    $tenant = Tenant::create();

    $this
        ->withoutExceptionHandling()
        ->get('test?tenant=' . $tenant->id)
        ->assertSee($tenant->id);
});

test('cookie identification works', function () {
    $tenant = Tenant::create();

    $this
        ->withoutExceptionHandling()
        ->withUnencryptedCookie('X-Tenant', $tenant->id)
        ->get('test')
        ->assertSee($tenant->id);
});

test('middleware throws exception when tenant data is not provided in the request', function () {
    pest()->expectException(TenantCouldNotBeIdentifiedByRequestDataException::class);
    $this->withoutExceptionHandling()->get('test');
});
