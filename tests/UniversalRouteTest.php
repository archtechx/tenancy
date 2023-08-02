<?php

declare(strict_types=1);

use Stancl\Tenancy\Tenancy;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Stancl\Tenancy\Tests\Etc\Tenant;
use Illuminate\Contracts\Http\Kernel;
use Stancl\Tenancy\Resolvers\PathTenantResolver;
use Illuminate\Routing\Controller as BaseController;
use Illuminate\Support\Facades\Route as RouteFacade;
use Stancl\Tenancy\Actions\CloneRoutesAsTenant;
use Stancl\Tenancy\Middleware\InitializeTenancyByPath;
use Stancl\Tenancy\Middleware\IdentificationMiddleware;
use Stancl\Tenancy\Resolvers\RequestDataTenantResolver;
use Stancl\Tenancy\Middleware\InitializeTenancyByDomain;
use Stancl\Tenancy\Concerns\UsableWithEarlyIdentification;
use Stancl\Tenancy\Middleware\InitializeTenancyBySubdomain;
use Stancl\Tenancy\Middleware\InitializeTenancyByRequestData;
use Stancl\Tenancy\Middleware\PreventAccessFromUnwantedDomains;
use Stancl\Tenancy\Middleware\InitializeTenancyByDomainOrSubdomain;
use Stancl\Tenancy\Exceptions\TenantCouldNotBeIdentifiedByPathException;
use Stancl\Tenancy\Exceptions\TenantCouldNotBeIdentifiedOnDomainException;
use Stancl\Tenancy\Exceptions\MiddlewareNotUsableWithUniversalRoutesException;
use Stancl\Tenancy\Exceptions\TenantCouldNotBeIdentifiedByRequestDataException;
use Stancl\Tenancy\RouteMode;

test('a route can be universal using domain identification', function (array $routeMiddleware, array $globalMiddleware) {
    foreach ($globalMiddleware as $middleware) {
        // Instead of a global 'universal' MW, we use the default_route_mode config key to make routes universal by default
        if ($middleware === 'universal') {
            config(['tenancy.default_route_mode' => RouteMode::UNIVERSAL]);
        } else {
            app(Kernel::class)->pushMiddleware($middleware);
        }
    }

    RouteFacade::get('/foo', function () {
        return tenancy()->initialized
            ? 'Tenancy is initialized.'
            : 'Tenancy is not initialized.';
    })->middleware($routeMiddleware);

    $tenant = Tenant::create();

    $tenant->domains()->create([
        'domain' => $tenantDomain = $tenant->getTenantKey() . '.localhost',
    ]);

    pest()->get("http://localhost/foo")
        ->assertSuccessful()
        ->assertSee('Tenancy is not initialized.');

    pest()->get("http://{$tenantDomain}/foo")
        ->assertSuccessful()
        ->assertSee('Tenancy is initialized.');
})->with('domain identification types');

test('a route can be universal using subdomain identification', function (array $routeMiddleware, array $globalMiddleware) {
    foreach ($globalMiddleware as $middleware) {
        if ($middleware === 'universal') {
            config(['tenancy.default_route_mode' => RouteMode::UNIVERSAL]);
        } else {
            app(Kernel::class)->pushMiddleware($middleware);
        }
    }

    RouteFacade::get('/foo', function () {
        return tenancy()->initialized
            ? 'Tenancy is initialized.'
            : 'Tenancy is not initialized.';
    })->middleware($routeMiddleware);

    $tenant = Tenant::create();
    $tenantKey = $tenant->getTenantKey();

    $tenant->domains()->create([
        'domain' => $tenantKey,
    ]);

    pest()->get("http://localhost/foo")
        ->assertSuccessful()
        ->assertSee('Tenancy is not initialized.');

    pest()->get("http://{$tenantKey}.localhost/foo")
        ->assertSuccessful()
        ->assertSee('Tenancy is initialized.');
})->with('subdomain identification types');

test('a route can be universal using domainOrSubdomain identification', function (array $routeMiddleware, array $globalMiddleware) {
    foreach ($globalMiddleware as $middleware) {
        if ($middleware === 'universal') {
            config(['tenancy.default_route_mode' => RouteMode::UNIVERSAL]);
        } else {
            app(Kernel::class)->pushMiddleware($middleware);
        }
    }

    RouteFacade::get('/foo', function () {
        return tenancy()->initialized
            ? 'Tenancy is initialized.'
            : 'Tenancy is not initialized.';
    })->middleware($routeMiddleware);

    $tenant = Tenant::create();

    $tenant->domains()->create([
        'domain' => $tenantDomain = 'tenant-domain.test',
    ]);

    $tenant->domains()->create([
        'domain' => $tenantSubdomain = 'tenant-subdomain',
    ]);

    pest()->get("http://localhost/foo")
        ->assertSuccessful()
        ->assertSee('Tenancy is not initialized.');

    // Domain identification
    pest()->get("http://{$tenantDomain}/foo")
        ->assertSuccessful()
        ->assertSee('Tenancy is initialized.');

    // Subdomain identification
    pest()->get("http://{$tenantSubdomain}.localhost/foo")
        ->assertSuccessful()
        ->assertSee('Tenancy is initialized.');
})->with('domainOrSubdomain identification types');

test('a route can be universal using request data identification', function (array $routeMiddleware, array $globalMiddleware) {
    foreach ($globalMiddleware as $middleware) {
        if ($middleware === 'universal') {
            config(['tenancy.default_route_mode' => RouteMode::UNIVERSAL]);
        } else {
            app(Kernel::class)->pushMiddleware($middleware);
        }
    }

    RouteFacade::get('/foo', function () {
        return tenancy()->initialized
            ? 'Tenancy is initialized.'
            : 'Tenancy is not initialized.';
    })->middleware($routeMiddleware);

    $tenantKey = Tenant::create()->getTenantKey();

    pest()->get("http://localhost/foo")
        ->assertSuccessful()
        ->assertSee('Tenancy is not initialized.');

    pest()->get("http://localhost/foo?tenant={$tenantKey}")
        ->assertSuccessful()
        ->assertSee('Tenancy is initialized.');
})->with('request data identification types');

test('a route can be universal using path identification', function (array $routeMiddleware, array $globalMiddleware) {
    foreach ($globalMiddleware as $middleware) {
        if ($middleware === 'universal') {
            config(['tenancy.default_route_mode' => RouteMode::UNIVERSAL]);
        } else {
            app(Kernel::class)->pushMiddleware($middleware);
        }
    }

    RouteFacade::get('/foo', function () {
        return tenancy()->initialized
            ? 'Tenancy is initialized.'
            : 'Tenancy is not initialized.';
    })->middleware($routeMiddleware);

    /** @var CloneRoutesAsTenant $reregisterRoutesAction */
    $reregisterRoutesAction = app(CloneRoutesAsTenant::class);

    $reregisterRoutesAction->handle();

    $tenantKey = Tenant::create()->getTenantKey();

    pest()->get("http://localhost/foo")
        ->assertSuccessful()
        ->assertSee('Tenancy is not initialized.');

    pest()->get("http://localhost/{$tenantKey}/foo")
        ->assertSuccessful()
        ->assertSee('Tenancy is initialized.');
})->with('path identification types');

test('correct exception is thrown when route is universal and tenant could not be identified using domain identification', function (array $routeMiddleware, array $globalMiddleware) {
    foreach ($globalMiddleware as $middleware) {
        if ($middleware === 'universal') {
            config(['tenancy.default_route_mode' => RouteMode::UNIVERSAL]);
        } else {
            app(Kernel::class)->pushMiddleware($middleware);
        }
    }

    RouteFacade::get('/foo', function () {
        return tenancy()->initialized
            ? 'Tenancy is initialized.'
            : 'Tenancy is not initialized.';
    })->middleware($routeMiddleware);

    pest()->expectException(TenantCouldNotBeIdentifiedOnDomainException::class);
    $this->withoutExceptionHandling()->get('http://nonexistent_domain.localhost/foo');
})->with('domain identification types');

test('correct exception is thrown when route is universal and tenant could not be identified using subdomain identification', function (array $routeMiddleware, array $globalMiddleware) {
    foreach ($globalMiddleware as $middleware) {
        if ($middleware === 'universal') {
            config(['tenancy.default_route_mode' => RouteMode::UNIVERSAL]);
        } else {
            app(Kernel::class)->pushMiddleware($middleware);
        }
    }

    RouteFacade::get('/foo', function () {
        return tenancy()->initialized
            ? 'Tenancy is initialized.'
            : 'Tenancy is not initialized.';
    })->middleware($routeMiddleware);

    pest()->expectException(TenantCouldNotBeIdentifiedOnDomainException::class);
    $this->withoutExceptionHandling()->get('http://nonexistent_subdomain.localhost/foo');
})->with('subdomain identification types');

test('correct exception is thrown when route is universal and tenant could not be identified using path identification', function (array $routeMiddleware, array $globalMiddleware) {
    foreach ($globalMiddleware as $middleware) {
        if ($middleware === 'universal') {
            config(['tenancy.default_route_mode' => RouteMode::UNIVERSAL]);
        } else {
            app(Kernel::class)->pushMiddleware($middleware);
        }
    }

    RouteFacade::get('/foo', fn () => tenant() ? 'Tenancy is initialized.' : 'Tenancy is not initialized.')->middleware($routeMiddleware)->name('foo');

    /** @var CloneRoutesAsTenant $reregisterRoutesAction */
    $reregisterRoutesAction = app(CloneRoutesAsTenant::class);

    $reregisterRoutesAction->handle();

    pest()->expectException(TenantCouldNotBeIdentifiedByPathException::class);
    $this->withoutExceptionHandling()->get('http://localhost/non_existent/foo');
})->with('path identification types');

test('correct exception is thrown when route is universal and tenant could not be identified using request data identification', function (array $routeMiddleware, array $globalMiddleware) {
    foreach ($globalMiddleware as $middleware) {
        if ($middleware === 'universal') {
            config(['tenancy.default_route_mode' => RouteMode::UNIVERSAL]);
        } else {
            app(Kernel::class)->pushMiddleware($middleware);
        }
    }

    RouteFacade::get('/foo', function () {
        return tenancy()->initialized
            ? 'Tenancy is initialized.'
            : 'Tenancy is not initialized.';
    })->middleware($routeMiddleware);

    pest()->expectException(TenantCouldNotBeIdentifiedByRequestDataException::class);
    $this->withoutExceptionHandling()->get('http://localhost/foo?tenant=nonexistent_tenant');
})->with('request data identification types');

test('tenant and central flags override the universal flag', function () {
    app(Kernel::class)->pushMiddleware(InitializeTenancyByRequestData::class);
    $tenant = Tenant::create();

    $route = RouteFacade::get('/route', fn () => tenant() ? 'Tenancy initialized.' : 'Tenancy not initialized.')->middleware('universal');

    // Route is universal
    pest()->get('/route')->assertOk()->assertSee('Tenancy not initialized.');
    pest()->get('/route?tenant=' . $tenant->getTenantKey())->assertOk()->assertSee('Tenancy initialized.');
    tenancy()->end();

    // Route is in tenant context
    $route->action['middleware'] = ['universal', 'tenant'];

    pest()->get('/route')->assertServerError(); // "Tenant could not be identified by request data with payload..."
    pest()->get('/route?tenant=' . $tenant->getTenantKey())->assertOk()->assertSee('Tenancy initialized.');
    tenancy()->end();

    // Route is in central context
    $route->action['middleware'] = ['universal', 'central'];

    pest()->get('/route')->assertOk()->assertSee('Tenancy not initialized.');
    pest()->get('/route?tenant=' . $tenant->getTenantKey())->assertOk()->assertSee('Tenancy not initialized.'); // Route is accessible, but the context is central
});

test('a route can be flagged as universal in both route modes', function (RouteMode $defaultRouteMode) {
    app(Kernel::class)->pushMiddleware(InitializeTenancyBySubdomain::class);
    app(Kernel::class)->pushMiddleware(PreventAccessFromUnwantedDomains::class);

    config(['tenancy.default_route_mode' => $defaultRouteMode]);

    RouteFacade::get('/universal', fn () => tenant() ? 'Tenancy is initialized.' : 'Tenancy is not initialized.')->middleware('universal');

    Tenant::create()->domains()->create(['domain' => $tenantSubdomain = 'tenant-subdomain']);

    pest()->get("http://localhost/universal")
        ->assertSuccessful()
        ->assertSee('Tenancy is not initialized.');

    pest()->get("http://{$tenantSubdomain}.localhost/universal")
        ->assertSuccessful()
        ->assertSee('Tenancy is initialized.');
})->with([
    'default to tenant routes' => RouteMode::TENANT,
    'default to central routes' => RouteMode::CENTRAL,
]);

test('ReregisterRoutesAsTenant registers prefixed duplicates of universal routes correctly', function (bool $kernelIdentification, bool $useController) {
    $routeMiddleware = ['universal'];

    if ($kernelIdentification) {
        app(Kernel::class)->pushMiddleware(InitializeTenancyByPath::class);
    } else {
        $routeMiddleware[] = InitializeTenancyByPath::class;
    }

    config(['tenancy.identification.resolvers.' . PathTenantResolver::class . '.tenant_parameter_name' => $tenantParameterName = 'team']);
    config(['tenancy.identification.resolvers.' . PathTenantResolver::class . '.tenant_route_name_prefix' => $tenantRouteNamePrefix = 'team-route.']);

    // Test that routes with controllers as well as routes with closure actions get re-registered correctly
    $universalRoute = RouteFacade::get('/home', $useController ? Controller::class : fn () => tenant() ? 'Tenancy initialized.' : 'Tenancy not initialized.')->middleware($routeMiddleware)->name('home');
    $centralRoute = RouteFacade::get('/central', fn () => true)->name('central');

    expect($routes = RouteFacade::getRoutes()->get())->toContain($universalRoute);
    expect($routes)->toContain($centralRoute);

    /** @var CloneRoutesAsTenant $reregisterRoutesAction */
    $reregisterRoutesAction = app(CloneRoutesAsTenant::class);

    $reregisterRoutesAction->handle();

    expect($routesAfterRegisteringDuplicates = RouteFacade::getRoutes()->get())
        ->toContain($universalRoute)
        ->toContain($centralRoute);

    $newRoute = collect($routesAfterRegisteringDuplicates)->filter(fn ($route) => ! in_array($route, $routes))->first();

    expect($newRoute->uri())->toBe('{' . $tenantParameterName . '}' . '/' . $universalRoute->uri());
    expect(tenancy()->getRouteMiddleware($newRoute))->toBe(array_merge(tenancy()->getRouteMiddleware($universalRoute), ['tenant']));

    $tenant = Tenant::create();

    pest()->get(route($centralRouteName = $universalRoute->getName()))->assertSee('Tenancy not initialized.');
    pest()->get(route($tenantRouteName = $newRoute->getName(), [$tenantParameterName => $tenant->getTenantKey()]))->assertSee('Tenancy initialized.');

    expect($tenantRouteName)->toBe($tenantRouteNamePrefix . $universalRoute->getName());
    expect($centralRouteName)->toBe($universalRoute->getName());
})->with([
    'kernel identification' => true,
    'route-level identification' => false,
// Creates a matrix (multiple with())
])->with([
    'use controller' => true,
    'use closure' => false
]);

test('tenant resolver methods return the correct names for configured values', function (string $configurableParameter, string $value) {
    $configurableParameterConfigKey = 'tenancy.identification.resolvers.' . PathTenantResolver::class . '.' . $configurableParameter;

    config([$configurableParameterConfigKey => $value]);

    // Note: The names of the methods are NOT dynamic (PathTenantResolver::tenantParameterName(), PathTenantResolver::tenantRouteNamePrefix())
    $resolverMethodName = str($configurableParameter)->camel()->toString();

    expect(PathTenantResolver::$resolverMethodName())->toBe($value);
})->with([
    ['tenant_parameter_name', 'parameter'],
    ['tenant_route_name_prefix', 'prefix']
]);

test('ReregisterRoutesAsTenant only re-registers routes with path identification by default', function () {
    app(Kernel::class)->pushMiddleware(InitializeTenancyByPath::class);

    $currentRouteCount = fn () => count(RouteFacade::getRoutes()->get());

    $initialRouteCount = $currentRouteCount();

    // Path identification is used globally, and this route doesn't use a specific identification middleware, meaning path identification is used and the route should get re-registered
    RouteFacade::get('/home', fn () => tenant() ? 'Tenancy initialized.' : 'Tenancy not initialized.')->middleware('universal')->name('home');
    // The route uses a specific identification middleware other than InitializeTenancyByPath – the route shouldn't get re-registered
    RouteFacade::get('/home-domain-id', fn () => tenant() ? 'Tenancy initialized.' : 'Tenancy not initialized.')->middleware(['universal', InitializeTenancyByDomain::class])->name('home-domain-id');

    expect($currentRouteCount())->toBe($newRouteCount = $initialRouteCount + 2);

    /** @var CloneRoutesAsTenant $reregisterRoutesAction */
    $reregisterRoutesAction = app(CloneRoutesAsTenant::class);

    $reregisterRoutesAction->handle();

    // Only one of the two routes gets re-registered
    expect($currentRouteCount())->toBe($newRouteCount + 1);
});

test('custom callbacks can be used for reregistering universal routes', function () {
    RouteFacade::get('/home', fn () => tenant() ? 'Tenancy initialized.' : 'Tenancy not initialized.')->middleware('universal')->name($routeName = 'home');

    /** @var CloneRoutesAsTenant $reregisterRoutesAction */
    $reregisterRoutesAction = app(CloneRoutesAsTenant::class);
    $currentRouteCount = fn () => count(RouteFacade::getRoutes()->get());
    $initialRouteCount = $currentRouteCount();

    // Skip re-registering the 'home' route
    $reregisterRoutesAction->cloneUsing($routeName, function (Route $route) {
        return;
    })->handle();

    // Expect route count to stay the same because the 'home' route re-registration gets skipped
    expect($initialRouteCount)->toEqual($currentRouteCount());

    // Modify the 'home' route re-registration so that a different route is registered
    $reregisterRoutesAction->cloneUsing($routeName, function (Route $route) {
        RouteFacade::get('/newly-registered-route', fn() => true)->name('new.home');
    })->handle();

    expect($currentRouteCount())->toEqual($initialRouteCount + 1);
});

test('reregistration of specific routes can get skipped', function () {
    RouteFacade::get('/home', fn () => tenant() ? 'Tenancy initialized.' : 'Tenancy not initialized.')->middleware('universal')->name($routeName = 'home');

    /** @var CloneRoutesAsTenant $reregisterRoutesAction */
    $reregisterRoutesAction = app(CloneRoutesAsTenant::class);
    $currentRouteCount = fn () => count(RouteFacade::getRoutes()->get());
    $initialRouteCount = $currentRouteCount();

    // Skip re-registering the 'home' route
    $reregisterRoutesAction->skipRoute($routeName)->handle();

    // Expect route count to stay the same because the 'home' route re-registration gets skipped
    expect($initialRouteCount)->toEqual($currentRouteCount());
});


test('identification middleware works with universal routes only when it implements MiddlewareUsableWithUniversalRoutes', function () {
    $tenantKey = Tenant::create()->getTenantKey();
    $routeAction = fn () => tenancy()->initialized ? $tenantKey : 'Tenancy is not initialized.';

    // Route with the package's request data identification middleware – implements MiddlewareUsableWithUniversalRoutes
    RouteFacade::get('/universal-route', $routeAction)->middleware(['universal', InitializeTenancyByRequestData::class]);

    // Routes with custom request data identification middleware – does not implement MiddlewareUsableWithUniversalRoutes
    RouteFacade::get('/custom-mw-universal-route', $routeAction)->middleware(['universal', CustomMiddleware::class]);
    RouteFacade::get('/custom-mw-tenant-route', $routeAction)->middleware(['tenant', CustomMiddleware::class]);

    // Ensure the custom identification middleware works with non-universal routes
    // This is tested here because this is the only test where the custom MW is used
    // No exception is thrown for this request since the route uses the TENANT middleware, not the UNIVERSAL middleware
    pest()->get('http://localhost/custom-mw-tenant-route?tenant=' . $tenantKey)->assertOk()->assertSee($tenantKey);

    pest()->get('http://localhost/universal-route')->assertOk();
    pest()->get('http://localhost/universal-route?tenant=' . $tenantKey)->assertOk()->assertSee($tenantKey);

    pest()->expectException(MiddlewareNotUsableWithUniversalRoutesException::class);
    $this->withoutExceptionHandling()->get('http://localhost/custom-mw-universal-route');
});

foreach ([
    'domain identification types' => [PreventAccessFromUnwantedDomains::class, InitializeTenancyByDomain::class],
    'subdomain identification types' => [PreventAccessFromUnwantedDomains::class, InitializeTenancyBySubdomain::class],
    'domainOrSubdomain identification types' => [PreventAccessFromUnwantedDomains::class, InitializeTenancyByDomainOrSubdomain::class],
    'path identification types' => [InitializeTenancyByPath::class],
    'request data identification types' => [InitializeTenancyByRequestData::class],
] as $datasetName => $middleware) {
    dataset($datasetName, [
        'kernel identification' => [
            'route_middleware' => ['universal'],
            'global_middleware' => $middleware,
        ],
        'route-level identification' => [
            'route_middleware' => ['universal', ...$middleware],
            'global_middleware' => [],
        ],
        'kernel identification + defaulting to universal routes' => [
            'route_middleware' => [],
            'global_middleware' => ['universal', ...$middleware],
        ],
        'route-level identification + defaulting to universal routes' => [
            'route_middleware' => $middleware,
            'global_middleware' => ['universal'],
        ],
    ]);
}

class Controller extends BaseController
{
    public function __invoke()
    {
        return tenant() ? 'Tenancy initialized.' : 'Tenancy not initialized.';
    }
}

class CustomMiddleware extends IdentificationMiddleware
{
    use UsableWithEarlyIdentification;

    public static string $header = 'X-Tenant';
    public static string $cookie = 'X-Tenant';
    public static string $queryParameter = 'tenant';

    public function __construct(
        protected Tenancy $tenancy,
        protected RequestDataTenantResolver $resolver,
    ) {
    }

    /** @return \Illuminate\Http\Response|mixed */
    public function handle(Request $request, Closure $next): mixed
    {
        if ($this->shouldBeSkipped(tenancy()->getRoute($request))) {
            // Allow accessing central route in kernel identification
            return $next($request);
        }

        return $this->initializeTenancy($request, $next, $this->getPayload($request));
    }

    protected function getPayload(Request $request): string|array|null
    {
        if (static::$header && $request->hasHeader(static::$header)) {
            return $request->header(static::$header);
        } elseif (static::$queryParameter && $request->has(static::$queryParameter)) {
            return $request->get(static::$queryParameter);
        } elseif (static::$cookie && $request->hasCookie(static::$cookie)) {
            return $request->cookie(static::$cookie);
        }

        return null;
    }
}
