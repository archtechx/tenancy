<?php

declare(strict_types=1);

use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Route;
use Stancl\Tenancy\Events\TenantCreated;
use Stancl\Tenancy\Exceptions\TenancyNotInitializedException;
use Stancl\Tenancy\Middleware\InitializeTenancyBySubdomain;
use Stancl\Tenancy\Middleware\ScopeSessions;
use Stancl\Tenancy\Tests\Etc\Tenant;

beforeEach(function () {
    Route::group([
        'middleware' => [StartSession::class, InitializeTenancyBySubdomain::class, ScopeSessions::class],
    ], function () {
        Route::get('/foo', function () {
            return 'true';
        });
    });

    Event::listen(TenantCreated::class, function (TenantCreated $event) {
        $tenant = $event->tenant;

        /** @var Tenant $tenant */
        $tenant->domains()->create([
            'domain' => $tenant->id,
        ]);
    });
});

test('tenant id is auto added to session if its missing', function () {
    $tenant = Tenant::create([
        'id' => 'acme',
    ]);

    pest()->get('http://acme.localhost/foo')
        ->assertSessionHas(ScopeSessions::$tenantIdKey, 'acme');
});

test('changing tenant id in session will abort the request', function () {
    $tenant = Tenant::create([
        'id' => 'acme',
    ]);

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

    $tenant = Tenant::create([
        'id' => 'acme',
    ]);

    pest()->expectException(TenancyNotInitializedException::class);
    pest()->withoutExceptionHandling()->get('http://acme.localhost/bar');
});
