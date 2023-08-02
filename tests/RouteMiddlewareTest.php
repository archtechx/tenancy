<?php

declare(strict_types=1);

use Illuminate\Contracts\Http\Kernel;
use Illuminate\Support\Facades\Route;
use Stancl\Tenancy\Middleware\InitializeTenancyByPath;
use Stancl\Tenancy\Middleware\InitializeTenancyByDomain;
use Stancl\Tenancy\Middleware\InitializeTenancyBySubdomain;
use Stancl\Tenancy\Middleware\InitializeTenancyByRequestData;
use Stancl\Tenancy\Middleware\PreventAccessFromUnwantedDomains;
use Stancl\Tenancy\Middleware\InitializeTenancyByDomainOrSubdomain;
use Stancl\Tenancy\Tests\Etc\EarlyIdentification\AdditionalMiddleware;

test('tenancy gets the route middleware correctly', function () {
    // Kernel middleware should get ignored
    app(Kernel::class)->pushMiddleware($kernelMiddleware = InitializeTenancyByRequestData::class);
    Route::middlewareGroup('additional', [AdditionalMiddleware::class, $duplicateMiddleware = InitializeTenancyByDomain::class]);
    Route::middlewareGroup('tenant', [$duplicateMiddleware, 'additional']);
    Route::middlewareGroup('middleware', ['tenant']);

    $route = Route::get('/testing-route', function () {
        return 'testing route';
    })->middleware([PreventAccessFromUnwantedDomains::class, 'middleware']);

    $expectedRouteMiddleware = [PreventAccessFromUnwantedDomains::class, AdditionalMiddleware::class, InitializeTenancyByDomain::class];

    $routeMiddleware = tenancy()->getRouteMiddleware($route);

    expect($routeMiddleware)->toContain(...$expectedRouteMiddleware);

    // Assert that there's no duplicate middleware
    expect(array_filter($routeMiddleware, fn ($middleware) => $middleware === $duplicateMiddleware))
        ->toContain($duplicateMiddleware)->toHaveCount(1);

    expect($routeMiddleware)->not()->toContain($kernelMiddleware);
});

test('tenancy detects presence of route middleware correctly', function (string $identificationMiddleware) {
    // Kernel middleware should get ignored
    app(Kernel::class)->pushMiddleware(InitializeTenancyByRequestData::class);

    // The 'second-level' group has the identification middleware
    // The 'surface' group has a 'first-level' group, and that group has a 'second-level' group (three middleware group layers)
    // Test that the identification middleware is detected even when packed in a middleware group three layers deep
    Route::middlewareGroup($middlewareGroup = 'surface', ['first-level']);
    Route::middlewareGroup('first-level', ['second-level']);
    Route::middlewareGroup('second-level', [$identificationMiddleware]);

    $routeWithIdentificationMiddleware = Route::get('/tenant-route', function () {
        return 'tenant route';
    })->middleware($middlewareGroup);

    $route = Route::get('/central-route', function () {
        return 'central route';
    });

    expect(tenancy()->routeHasMiddleware($routeWithIdentificationMiddleware, $identificationMiddleware))->toBeTrue();
    expect(tenancy()->routeHasMiddleware($route, $identificationMiddleware))->toBeFalse();

    // Look specifically for identification middleware
    expect(tenancy()->routeHasIdentificationMiddleware($routeWithIdentificationMiddleware))->toBeTrue();
    expect(tenancy()->routeHasIdentificationMiddleware($route))->toBeFalse();
})->with([
    InitializeTenancyByPath::class,
    InitializeTenancyByRequestData::class,
    InitializeTenancyByDomain::class,
    InitializeTenancyBySubdomain::class,
    InitializeTenancyByDomainOrSubdomain::class,
]);

test('domain identification middleware is configurable', function() {
    $route = Route::get('/welcome-route', fn () => 'welcome')->middleware([InitializeTenancyByDomain::class]);

    config(['tenancy.identification.domain_identification_middleware' => []]);

    expect(tenancy()->routeHasDomainIdentificationMiddleware($route))->toBeFalse();

    // Set domain identification middleware list back to default
    config(['tenancy.identification.domain_identification_middleware' => [
        InitializeTenancyByDomain::class,
        InitializeTenancyBySubdomain::class,
        InitializeTenancyByDomainOrSubdomain::class,
    ]]);

    expect(tenancy()->routeHasDomainIdentificationMiddleware($route))->toBeTrue();
});
