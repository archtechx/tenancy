<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Stancl\Tenancy\Database\Concerns\HasDomains;
use Stancl\Tenancy\Exceptions\NotASubdomainException;
use Stancl\Tenancy\Middleware\InitializeTenancyBySubdomain;
use Stancl\Tenancy\Database\Models;
use function Stancl\Tenancy\Tests\pest;

beforeEach(function () {
    // Global state cleanup after some tests
    InitializeTenancyBySubdomain::$onFail = null;

    Route::group([
        'middleware' => InitializeTenancyBySubdomain::class,
    ], function () {
        Route::get('/foo/{a}/{b}', function ($a, $b) {
            return "$a + $b";
        });
    });

    config(['tenancy.models.tenant' => SubdomainTenant::class]);
});

test('tenant can be identified by subdomain', function () {
    $tenant = SubdomainTenant::create([
        'id' => 'acme',
    ]);

    $tenant->domains()->create([
        'domain' => 'foo',
    ]);

    expect(tenancy()->initialized)->toBeFalse();

    pest()
        ->get('http://foo.localhost/foo/abc/xyz')
        ->assertSee('abc + xyz');

    expect(tenancy()->initialized)->toBeTrue();
    expect(tenant('id'))->toBe('acme');
});

test('onfail logic can be customized', function () {
    InitializeTenancyBySubdomain::$onFail = function () {
        return response('foo');
    };

    pest()
        ->get('http://foo.localhost/foo/abc/xyz')
        ->assertSee('foo');
});

test('archte.ch is not a valid subdomain', function () {
    pest()->expectException(NotASubdomainException::class);

    // This gets routed to the app, but with a request domain of 'archte.ch'
    $this
        ->withoutExceptionHandling()
        ->get('http://archte.ch/foo/abc/xyz');
});

test('ip address is not a valid subdomain', function () {
    pest()->expectException(NotASubdomainException::class);

    $this
        ->withoutExceptionHandling()
        ->get('http://127.0.0.2/foo/abc/xyz');
});

test('oninvalidsubdomain logic can be customized', function () {
    // in this case, we need to return a response instance
    // since a string would be treated as the subdomain
    InitializeTenancyBySubdomain::$onFail = function ($e) {
        if ($e instanceof NotASubdomainException) {
            return response('foo custom invalid subdomain handler');
        }

        throw $e;
    };

    $this
        ->withoutExceptionHandling()
        ->get('http://127.0.0.2/foo/abc/xyz')
        ->assertSee('foo custom invalid subdomain handler');
});

test('we cant use a subdomain that doesnt belong to our central domains', function () {
    config(['tenancy.identification.central_domains' => [
        '127.0.0.1',
        // not 'localhost'
    ]]);

    $tenant = SubdomainTenant::create([
        'id' => 'acme',
    ]);

    $tenant->domains()->create([
        'domain' => 'foo',
    ]);

    pest()->expectException(NotASubdomainException::class);

    $this
        ->withoutExceptionHandling()
        ->get('http://foo.localhost/foo/abc/xyz');
});

class SubdomainTenant extends Models\Tenant
{
    use HasDomains;
}
