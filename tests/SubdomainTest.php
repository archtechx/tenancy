<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Stancl\Tenancy\Database\Concerns\HasDomains;
use Stancl\Tenancy\Exceptions\NotASubdomainException;
use Stancl\Tenancy\Middleware\InitializeTenancyBySubdomain;
use Stancl\Tenancy\Database\Models;

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

    config(['tenancy.tenant_model' => SubdomainTenant::class]);
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
        return 'foo';
    };

    pest()
        ->get('http://foo.localhost/foo/abc/xyz')
        ->assertSee('foo');
});

test('localhost is not a valid subdomain', function () {
    pest()->expectException(NotASubdomainException::class);

    $this
        ->withoutExceptionHandling()
        ->get('http://localhost/foo/abc/xyz');
});

test('ip address is not a valid subdomain', function () {
    pest()->expectException(NotASubdomainException::class);

    $this
        ->withoutExceptionHandling()
        ->get('http://127.0.0.1/foo/abc/xyz');
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
        ->get('http://127.0.0.1/foo/abc/xyz')
        ->assertSee('foo custom invalid subdomain handler');
});

test('we cant use a subdomain that doesnt belong to our central domains', function () {
    config(['tenancy.central_domains' => [
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

test('central domain is not a subdomain', function () {
    config(['tenancy.central_domains' => [
        'localhost',
    ]]);

    $tenant = SubdomainTenant::create([
        'id' => 'acme',
    ]);

    $tenant->domains()->create([
        'domain' => 'acme',
    ]);

    pest()->expectException(NotASubdomainException::class);

    $this
        ->withoutExceptionHandling()
        ->get('http://localhost/foo/abc/xyz');
});

class SubdomainTenant extends Models\Tenant
{
    use HasDomains;
}
