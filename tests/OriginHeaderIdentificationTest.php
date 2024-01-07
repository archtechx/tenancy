<?php

declare(strict_types=1);

use Stancl\Tenancy\Tests\Etc\Tenant;
use Illuminate\Support\Facades\Route;
use Stancl\Tenancy\Middleware\InitializeTenancyByOriginHeader;

beforeEach(function () {
    InitializeTenancyByOriginHeader::$onFail = null;

    config([
        'tenancy.central_domains' => [
            'localhost',
        ],
    ]);

    Route::post('/home', function () {
        return response(tenant('id'));
    })->middleware([InitializeTenancyByOriginHeader::class])->name('home');
});

afterEach(function () {
    InitializeTenancyByOriginHeader::$onFail = null;
});

test('origin identification works', function () {
    $tenant = Tenant::create();

    $tenant->domains()->create([
        'domain' => 'foo',
    ]);

    pest()
        ->withHeader('Origin', 'foo.localhost')
        ->post('home')
        ->assertSee($tenant->id);
});

test('tenant routes are not accessible on central domains while using origin identification', function () {
    pest()
        ->withHeader('Origin', 'localhost')
        ->post('home')
        ->assertStatus(500);
});

test('onfail logic can be customized', function() {
    InitializeTenancyByOriginHeader::$onFail = function () {
        return response('onFail message');
    };

    pest()
        ->withHeader('Origin', 'bar.localhost') // 'bar'/'bar.localhost' is not an existing tenant domain
        ->post('home')
        ->assertSee('onFail message');
});
