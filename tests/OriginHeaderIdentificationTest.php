<?php

declare(strict_types=1);

use Stancl\Tenancy\Tests\Etc\Tenant;
use Illuminate\Support\Facades\Route;
use Stancl\Tenancy\Middleware\InitializeTenancyByOriginHeader;
use function Stancl\Tenancy\Tests\pest;

beforeEach(function () {
    InitializeTenancyByOriginHeader::$onFail = null;

    config([
        'tenancy.identification.central_domains' => [
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
    $tenant = Tenant::create();

    $tenant->domains()->create([
        'domain' => 'foo',
    ]);

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

test('origin identification can be used with universal routes', function () {
    $tenant = Tenant::create();

    $tenant->domains()->create([
        'domain' => 'foo',
    ]);

    Route::post('/universal', function () {
        return response(tenant('id') ?? 'central');
    })->middleware([InitializeTenancyByOriginHeader::class, 'universal'])->name('universal');

    pest()
        ->withHeader('Origin', 'foo.localhost')
        ->post('universal')
        ->assertSee($tenant->id);

    tenancy()->end();

    pest()
        ->withHeader('Origin', 'localhost')
        ->post('universal')
        ->assertSee('central');

    pest()
        // no header
        ->post('universal')
        ->assertSee('central');
});

test('origin identification can be used with both domains and subdomains', function () {
    $foo = Tenant::create();
    $foo->domains()->create(['domain' => 'foo']);

    $bar = Tenant::create();
    $bar->domains()->create(['domain' => 'bar.localhost']);

    pest()
        ->withHeader('Origin', 'foo.localhost')
        ->post('home')
        ->assertSee($foo->id);

    pest()
        ->withHeader('Origin', 'bar.localhost')
        ->post('home')
        ->assertSee($bar->id);
});
