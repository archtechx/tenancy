<?php

declare(strict_types=1);

use Illuminate\Contracts\Http\Kernel;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Route;
use Stancl\Tenancy\Events\TenancyInitialized;
use Stancl\Tenancy\Middleware\InitializeTenancyByDomain;
use Stancl\Tenancy\Middleware\InitializeTenancyByDomainOrSubdomain;
use Stancl\Tenancy\Middleware\InitializeTenancyByPath;
use Stancl\Tenancy\Middleware\InitializeTenancyByRequestData;
use Stancl\Tenancy\Middleware\InitializeTenancyBySubdomain;
use Stancl\Tenancy\Middleware\PreventAccessFromUnwantedDomains;
use Stancl\Tenancy\Tests\Etc\EarlyIdentification\Controller;
use Stancl\Tenancy\Tests\Etc\Tenant;

beforeEach(function () {
    config()->set([
        'tenancy.token' => 'central-abc123',
    ]);

    Event::listen(TenancyInitialized::class, function (TenancyInitialized $event) {
        config()->set([
            'tenancy.token' => $event->tenancy->tenant->getTenantKey() . '-abc123',
        ]);
    });
});

test('early identification works with path identification', function () {
    app(Kernel::class)->pushMiddleware(InitializeTenancyByPath::class);

    Route::group([
        'prefix' => '/{tenant}',
    ], function () {
        Route::get('/foo', [Controller::class, 'index'])->name('foo');
    });

    Tenant::create([
        'id' => 'acme',
    ]);

    $response = pest()->get('/acme/foo')->assertOk();

    assertTenancyInitializedInEarlyIdentificationRequest($response->getContent());

    // check if default parameter feature is working fine by asserting that the route WITHOUT the tenant parameter
    // matches the route WITH the tenant parameter
    expect(route('foo'))->toBe(route('foo', ['tenant' => 'acme']));
});

test('early identification works with request data identification', function (string $type) {
    app(Kernel::class)->pushMiddleware(InitializeTenancyByRequestData::class);

    Route::get('/foo', [Controller::class, 'index'])->name('foo');

    $tenant = Tenant::create([
        'id' => 'acme',
    ]);

    if ($type === 'header') {
        $response = pest()->get('/foo',  ['X-Tenant' => $tenant->id])->assertOk();
    } elseif ($type === 'queryParameter') {
        $response = pest()->get("/foo?tenant=$tenant->id")->assertOk();
    }

    assertTenancyInitializedInEarlyIdentificationRequest($response->getContent());
})->with([
    'using request header parameter' => 'header',
    'using request query parameter' => 'queryParameter'
]);

// The name of this test is suffixed by the dataset — domain / subdomain / domainOrSubdomain identification
test('early identification works', function (string $middleware, string $domain, string $url) {
    app(Kernel::class)->pushMiddleware($middleware);

    config(['tenancy.tenant_model' => Tenant::class]);

    Route::get('/foo', [Controller::class, 'index'])
        ->middleware(PreventAccessFromUnwantedDomains::class)
        ->name('foo');

    $tenant = Tenant::create();

    $tenant->domains()->create([
        'domain' => $domain,
    ]);

    $response = pest()->get($url)->assertOk();

    assertTenancyInitializedInEarlyIdentificationRequest($response->getContent());
})->with([
    'domain identification' => ['middleware' => InitializeTenancyByDomain::class, 'domain' => 'foo.test', 'url' => 'http://foo.test/foo'],
    'subdomain identification' => ['middleware' => InitializeTenancyBySubdomain::class, 'domain' => 'foo', 'url' => 'http://foo.localhost/foo'],
    'domainOrSubdomain identification using domain' => ['middleware' => InitializeTenancyByDomainOrSubdomain::class, 'domain' => 'foo.test', 'url' => 'http://foo.test/foo'],
    'domainOrSubdomain identification using subdomain' => ['middleware' => InitializeTenancyByDomainOrSubdomain::class, 'domain' => 'foo', 'url' => 'http://foo.localhost/foo'],
]);

function assertTenancyInitializedInEarlyIdentificationRequest(string|false $string): void
{
    expect($string)->toBe(tenant()->getTenantKey() . '-abc123'); // Assert that the service class returns tenant value
    expect(app()->make('additionalMiddlewareRunsInTenantContext'))->toBeTrue(); // Assert that middleware added in the controller constructor runs in tenant context
    expect(app()->make('controllerRunsInTenantContext'))->toBeTrue(); // Assert that tenancy is initialized in the controller constructor
}
