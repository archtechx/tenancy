<?php

declare(strict_types=1);

use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\Facades\Route;
use Stancl\Tenancy\Exceptions\TenancyNotInitializedException;
use Stancl\Tenancy\Middleware\InitializeTenancyBySubdomain;
use Stancl\Tenancy\Middleware\ScopeSessions;
use Stancl\Tenancy\Tests\Etc\Tenant;
use function Stancl\Tenancy\Tests\pest;

beforeEach(function () {
    Route::group([
        'middleware' => [StartSession::class, InitializeTenancyBySubdomain::class, ScopeSessions::class],
    ], function () {
        Route::get('/foo', function () {
            return 'true';
        });
    });
});

test('tenant id is auto added to session if its missing', function () {
    Tenant::create([
        'id' => 'acme',
    ])->createDomain('acme');

    pest()->get('http://acme.localhost/foo')
        ->assertSessionHas(ScopeSessions::$tenantIdKey, 'acme');
});

test('changing tenant id in session will abort the request', function () {
    Tenant::create([
        'id' => 'acme',
    ])->createDomain('acme');

    pest()->get('http://acme.localhost/foo')
        ->assertSuccessful();

    session()->put(ScopeSessions::$tenantIdKey, 'foobar');

    pest()->get('http://acme.localhost/foo')
        ->assertStatus(403);
});

test('an exception is thrown when the middleware is executed before tenancy is initialized', function () {
    Route::get('/bar', function () {
        return true;
    })->middleware([StartSession::class, ScopeSessions::class]);

    Tenant::create([
        'id' => 'acme',
    ])->createDomain('acme');

    pest()->expectException(TenancyNotInitializedException::class);
    $this->withoutExceptionHandling()->get('http://acme.localhost/bar');
});

test('scope sessions mw can be used on universal routes', function() {
    Route::get('/universal', function () {
        return true;
    })->middleware(['universal', InitializeTenancyBySubdomain::class, ScopeSessions::class]);

    Tenant::create([
        'id' => 'acme',
    ])->createDomain('acme');

    pest()->withoutExceptionHandling()->get('http://localhost/universal')->assertSuccessful();
});
