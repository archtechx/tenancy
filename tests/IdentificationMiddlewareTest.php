<?php

declare(strict_types=1);

use Illuminate\Contracts\Http\Kernel;
use Illuminate\Support\Facades\Route;
use Stancl\Tenancy\Middleware\IdentificationMiddleware;
use Stancl\Tenancy\Middleware\InitializeTenancyByDomain;
use Stancl\Tenancy\Middleware\InitializeTenancyByDomainOrSubdomain;
use Stancl\Tenancy\Middleware\InitializeTenancyByPath;
use Stancl\Tenancy\Middleware\InitializeTenancyByRequestData;
use Stancl\Tenancy\Middleware\InitializeTenancyBySubdomain;
use Stancl\Tenancy\Exceptions\TenantIsPendingException;
use Stancl\Tenancy\Resolvers\DomainTenantResolver;
use Stancl\Tenancy\Resolvers\PathTenantResolver;
use Stancl\Tenancy\Resolvers\RequestDataTenantResolver;
use Stancl\Tenancy\Tests\Etc\Tenant;
use function Stancl\Tenancy\Tests\pest;

beforeEach(function () {
    IdentificationMiddleware::$terminateTenancy = false;
});

afterEach(function () {
    IdentificationMiddleware::$terminateTenancy = false;
});

dataset('domain identification middleware', [
    InitializeTenancyByDomain::class,
    InitializeTenancyByDomainOrSubdomain::class,
]);

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

test('pending tenants can be excluded from identification and are not cached', function (string $middleware, string $resolver, string $url) {
    config([
        'tenancy.pending.include_in_queries' => true,
        'tenancy.pending.exclude_from_identification' => false,
        'tenancy.identification.resolvers.' . $resolver . '.cache' => true,
    ]);

    Route::get($middleware === InitializeTenancyByPath::class ? '/{tenant}/bar' : '/bar', fn () => tenant('id'))->middleware($middleware);

    $tenant = Tenant::createPending();
    $tenant->domains()->create(['domain' => 'foo.test']);
    $tenant->domains()->create(['domain' => 'foo']);

    $url = str_replace('{id}', $tenant->id, $url);
    $cached = fn () => collect(app($resolver)->getPossibleCacheKeys($tenant))->contains(fn (string $key) => global_cache()->has($key));

    pest()->get($url)->assertSee($tenant->id);
    expect($cached())->toBeFalse(); // pending tenants are never cached regardless of exclude_from_identification setting

    tenancy()->end();
    config(['tenancy.pending.exclude_from_identification' => true]);
    pest()->withoutExceptionHandling();

    // With InitializeTenancyByDomainOrSubdomain, this also checks that the full domain fallback doesn't replace the exception
    expect(fn () => pest()->get($url))->toThrow(fn (TenantIsPendingException $e) => expect($e->tenant->is($tenant))->toBeTrue());
    expect(tenancy()->initialized)->toBeFalse();
    expect($cached())->toBeFalse();

    Tenant::pullPendingFromPool();

    pest()->get($url)->assertSee($tenant->id);
    expect($cached())->toBeTrue();
})->with([
    'domain' => [InitializeTenancyByDomain::class, DomainTenantResolver::class, 'http://foo.test/bar'],
    'subdomain' => [InitializeTenancyBySubdomain::class, DomainTenantResolver::class, 'http://foo.localhost/bar'],
    'domain or subdomain (domain)' => [InitializeTenancyByDomainOrSubdomain::class, DomainTenantResolver::class, 'http://foo.test/bar'],
    'domain or subdomain (subdomain)' => [InitializeTenancyByDomainOrSubdomain::class, DomainTenantResolver::class, 'http://foo.localhost/bar'],
    'path' => [InitializeTenancyByPath::class, PathTenantResolver::class, 'http://localhost/{id}/bar'],
    'request data' => [InitializeTenancyByRequestData::class, RequestDataTenantResolver::class, 'http://localhost/bar?tenant={id}'],
]);
