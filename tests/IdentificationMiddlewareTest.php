<?php

declare(strict_types=1);

use Illuminate\Contracts\Http\Kernel;
use Illuminate\Support\Facades\Route;
use Stancl\Tenancy\Middleware\IdentificationMiddleware;
use Stancl\Tenancy\Middleware\InitializeTenancyByDomain;
use Stancl\Tenancy\Middleware\InitializeTenancyByDomainOrSubdomain;
use Stancl\Tenancy\Tests\Etc\Tenant;
use function Stancl\Tenancy\Tests\pest;

beforeEach(function () {
    IdentificationMiddleware::$forceReset = false;
    IdentificationMiddleware::$terminateTenancy = false;
});

afterEach(function () {
    IdentificationMiddleware::$forceReset = false;
    IdentificationMiddleware::$terminateTenancy = false;
});

dataset('domain identification middleware', [
    InitializeTenancyByDomain::class,
    InitializeTenancyByDomainOrSubdomain::class,
]);

test('forceReset makes the tenant get re-resolved on each request', function (bool $forceReset, string $expectedDomains, string $middleware) {
    IdentificationMiddleware::$forceReset = $forceReset;

    Route::get('/domains', fn () => tenant()->domains->pluck('domain')->sort()->join(','))
        ->middleware($middleware);

    $tenant = Tenant::create();
    $tenant->domains()->create(['domain' => 'a.localhost']);

    pest()->get('http://a.localhost/domains')->assertSee('a.localhost');

    $tenant->domains()->create(['domain' => 'b.localhost']);

    // Without forceReset, initialize() is a noop since the resolved tenant matches tenant(),
    // so tenant() is still the instance from the first request, with stale loaded relations.
    expect(pest()->get('http://a.localhost/domains')->getContent())->toBe($expectedDomains);
})->with([
    'forceReset enabled' => [true, 'a.localhost,b.localhost'],
    'forceReset disabled' => [false, 'a.localhost'],
])->with('domain identification middleware');

test('terminateTenancy ends tenancy after the request', function (bool $terminateTenancy, string $middleware) {
    IdentificationMiddleware::$terminateTenancy = $terminateTenancy;

    Route::get('/tenant-id', fn () => tenant('id'))->middleware($middleware);

    $tenant = Tenant::create();
    $tenant->domains()->create(['domain' => 'a.localhost']);

    // Tenancy is initialized while the request is being handled either way
    pest()->get('http://a.localhost/tenant-id')->assertSee($tenant->id);

    expect(tenancy()->initialized)->toBe(! $terminateTenancy);
})->with([
    'terminateTenancy enabled' => true,
    'terminateTenancy disabled' => false,
])->with('domain identification middleware');

test('terminateTenancy ends tenancy before subsequent central requests with kernel identification', function () {
    IdentificationMiddleware::$terminateTenancy = true;

    app(Kernel::class)->pushMiddleware(InitializeTenancyByDomain::class);

    Route::get('/tenant-route', fn () => tenant('id'))->middleware('tenant');
    Route::get('/central-route', fn () => tenant('id') ?? 'central');

    $tenant = Tenant::create();
    $tenant->domains()->create(['domain' => 'a.localhost']);

    pest()->get('http://a.localhost/tenant-route')->assertSee($tenant->id);

    expect(pest()->get('http://localhost/central-route')->getContent())->toBe('central');
});

test('terminateTenancy ends tenancy even if an exception propagates out of the request', function (string $middleware) {
    IdentificationMiddleware::$terminateTenancy = true;

    Route::get('/exception', fn () => throw new Exception('foo'))->middleware($middleware);

    $tenant = Tenant::create();
    $tenant->domains()->create(['domain' => 'a.localhost']);

    // With exception handling, the exception is rendered into a response before reaching the middleware
    pest()->get('http://a.localhost/exception')->assertStatus(500);
    expect(tenancy()->initialized)->toBeFalse();

    pest()->withoutExceptionHandling();

    expect(fn () => pest()->get('http://a.localhost/exception'))->toThrow(Exception::class, 'foo');
    expect(tenancy()->initialized)->toBeFalse();
})->with('domain identification middleware');
