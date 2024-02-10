<?php

declare(strict_types=1);

use Illuminate\Routing\Route;
use Illuminate\Support\Facades\DB;
use Stancl\Tenancy\Tests\Etc\Tenant;
use Stancl\Tenancy\Resolvers\PathTenantResolver;
use Stancl\Tenancy\Resolvers\DomainTenantResolver;
use Illuminate\Support\Facades\Route as RouteFacade;
use Stancl\Tenancy\Middleware\InitializeTenancyByPath;
use Stancl\Tenancy\PathIdentificationManager;
use Stancl\Tenancy\Resolvers\RequestDataTenantResolver;

test('tenants can be resolved using cached resolvers', function (string $resolver) {
    $tenant = Tenant::create(['id' => $tenantKey = 'acme']);

    $tenant->domains()->create(['domain' => $tenantKey]);

    expect($tenant->is(app($resolver)->resolve(getResolverArgument($resolver, $tenantKey))))->toBeTrue();
})->with([
    DomainTenantResolver::class,
    PathTenantResolver::class,
    RequestDataTenantResolver::class,
]);

test('the underlying resolver is not touched when using the cached resolver', function (string $resolver) {
    $tenant = Tenant::create(['id' => $tenantKey = 'acme']);

    $tenant->createDomain($tenantKey);

    DB::enableQueryLog();

    config(['tenancy.identification.resolvers.' . $resolver . '.cache' => false]);

    expect($tenant->is(app($resolver)->resolve(getResolverArgument($resolver, $tenantKey))))->toBeTrue();
    DB::flushQueryLog();
    expect($tenant->is(app($resolver)->resolve(getResolverArgument($resolver, $tenantKey))))->toBeTrue();

    pest()->assertNotEmpty(DB::getQueryLog()); // not empty

    config(['tenancy.identification.resolvers.' . $resolver . '.cache' => true]);

    expect($tenant->is(app($resolver)->resolve(getResolverArgument($resolver, $tenantKey))))->toBeTrue();
    DB::flushQueryLog();
    expect($tenant->is(app($resolver)->resolve(getResolverArgument($resolver, $tenantKey))))->toBeTrue();
    expect(DB::getQueryLog())->toBeEmpty(); // empty
})->with([
    DomainTenantResolver::class,
    PathTenantResolver::class,
    RequestDataTenantResolver::class,
]);

test('cache is invalidated when the tenant is updated', function (string $resolver) {
    $tenant = Tenant::create(['id' => $tenantKey = 'acme']);
    $tenant->createDomain($tenantKey);

    DB::enableQueryLog();

    config(['tenancy.identification.resolvers.' . $resolver . '.cache' => true]);

    expect($tenant->is(app($resolver)->resolve(getResolverArgument($resolver, $tenantKey))))->toBeTrue();
    expect(DB::getQueryLog())->not()->toBeEmpty();

    DB::flushQueryLog();

    expect($tenant->is(app($resolver)->resolve(getResolverArgument($resolver, $tenantKey))))->toBeTrue();
    expect(DB::getQueryLog())->toBeEmpty();

    // Tenant cache gets invalidated when the tenant is updated
    $tenant->touch();

    DB::flushQueryLog();

    expect($tenant->is(app($resolver)->resolve(getResolverArgument($resolver, $tenantKey))))->toBeTrue();

    expect(DB::getQueryLog())->not()->toBeEmpty(); // Cache was invalidated, so the tenant was retrievevd from the DB
})->with([
    DomainTenantResolver::class,
    PathTenantResolver::class,
    RequestDataTenantResolver::class,
]);

test('cache is invalidated when a tenants domain is changed', function () {
    $tenant = Tenant::create(['id' => $tenantKey = 'acme']);
    $tenant->createDomain($tenantKey);

    DB::enableQueryLog();

    config(['tenancy.identification.resolvers.' . DomainTenantResolver::class . '.cache' => true]);

    expect($tenant->is(app(DomainTenantResolver::class)->resolve('acme')))->toBeTrue();
    DB::flushQueryLog();
    expect($tenant->is(app(DomainTenantResolver::class)->resolve('acme')))->toBeTrue();
    expect(DB::getQueryLog())->toBeEmpty(); // empty

    $tenant->createDomain([
        'domain' => 'bar',
    ]);

    DB::flushQueryLog();
    expect($tenant->is(app(DomainTenantResolver::class)->resolve('acme')))->toBeTrue();
    pest()->assertNotEmpty(DB::getQueryLog()); // not empty

    DB::flushQueryLog();
    expect($tenant->is(app(DomainTenantResolver::class)->resolve('bar')))->toBeTrue();
    pest()->assertNotEmpty(DB::getQueryLog()); // not empty
});

test('PathTenantResolver forgets the tenant route parameter when the tenant is resolved from cache', function() {
    config(['tenancy.identification.resolvers.' . PathTenantResolver::class . '.cache' => true]);

    Tenant::create(['id' => 'foo']);

    RouteFacade::get('/', fn () => request()->route()->parameter('tenant') ? 'Tenant parameter present' : 'No tenant parameter')
        ->name('tenant-route')
        ->prefix('{tenant}')
        ->middleware(InitializeTenancyByPath::class);

    // Tenant gets cached on first request
    pest()->get("/foo")->assertSee('No tenant parameter');

    // Tenant is resolved from cache on second request
    // The tenant parameter should be forgotten
    DB::flushQueryLog();
    pest()->get("/foo")->assertSee('No tenant parameter');
    pest()->assertEmpty(DB::getQueryLog()); // resolved from cache
});

/**
 * Return the argument for the resolver – tenant key, or a route instance with the tenant parameter.
 *
 * PathTenantResolver uses a route instance with the tenant parameter as the argument,
 * unlike other resolvers which use a tenant key as the argument.
 *
 * This method is used in the tests where we test all the resolvers
 * to make getting the resolver arguments less repetitive (primarily because of the PathTenantResolver).
 */
function getResolverArgument(string $resolver, string $tenantKey): string|Route
{
    // PathTenantResolver uses a route instance as the argument
    if ($resolver === PathTenantResolver::class) {
        $routeName = 'tenant-route';

        // Find or create a route instance for the resolver
        $route = RouteFacade::getRoutes()->getByName($routeName) ?? RouteFacade::get('/', fn () => null)
            ->name($routeName)
            ->prefix('{tenant}')
            ->middleware(InitializeTenancyByPath::class);

        // To make the tenant available on the route instance
        // Make the 'tenant' route parameter the tenant key
        // Setting the parameter on the $route->parameters property is required
        // Because $route->setParameter() throws an exception when $route->parameters is not set yet
        $route->parameters[PathIdentificationManager::getTenantParameterName()] = $tenantKey;

        // Return the route instance with the tenant key as the 'tenant' parameter
        return $route;
    }

    // Resolvers other than PathTenantResolver use the tenant key as the argument
    // Return the tenant key
    return $tenantKey;
}
