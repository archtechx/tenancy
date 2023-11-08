<?php

declare(strict_types=1);

use Illuminate\Support\Arr;
use Illuminate\Routing\Route;
use Stancl\Tenancy\Enums\RouteMode;
use Stancl\Tenancy\Tests\Etc\Tenant;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Support\Facades\Event;
use Stancl\Tenancy\Events\TenancyInitialized;
use Illuminate\Support\Facades\Route as RouteFacade;
use Stancl\Tenancy\Actions\CloneRoutesAsTenant;
use Stancl\Tenancy\Middleware\InitializeTenancyByPath;
use Stancl\Tenancy\Middleware\InitializeTenancyByDomain;
use Stancl\Tenancy\Middleware\InitializeTenancyBySubdomain;
use Stancl\Tenancy\Bootstrappers\DatabaseTenancyBootstrapper;
use Stancl\Tenancy\Middleware\InitializeTenancyByRequestData;
use Stancl\Tenancy\Tests\Etc\EarlyIdentification\Models\Post;
use Stancl\Tenancy\Middleware\PreventAccessFromUnwantedDomains;
use Stancl\Tenancy\Middleware\InitializeTenancyByDomainOrSubdomain;
use Stancl\Tenancy\Tests\Etc\EarlyIdentification\ControllerWithMiddleware;
use Stancl\Tenancy\Tests\Etc\EarlyIdentification\ControllerWithRouteMiddleware;

beforeEach(function () {
    config()->set([
        'tenancy.test_service_token' => 'token:central',
    ]);

    Event::listen(TenancyInitialized::class, function (TenancyInitialized $event) {
        config()->set([
            'tenancy.test_service_token' => 'token:' . $event->tenancy->tenant->getTenantKey(),
        ]);
    });
});

test('early identification works with path identification', function (bool $useKernelIdentification, RouteMode $defaultRouteMode) {
    $identificationMiddleware = InitializeTenancyByPath::class;

    if ($useKernelIdentification) {
        $controller = ControllerWithMiddleware::class;
        app(Kernel::class)->pushMiddleware($identificationMiddleware);
    } else {
        $controller = ControllerWithRouteMiddleware::class;
        RouteFacade::middlewareGroup('tenant', [$identificationMiddleware]);
    }

    config(['tenancy.bootstrappers' => [DatabaseTenancyBootstrapper::class]]);
    config(['tenancy.default_route_mode' => $defaultRouteMode]);

    // Migrate users and comments tables on central connection
    pest()->artisan('migrate', [
        '--path' => __DIR__ . '/Etc/EarlyIdentification/path/migrations',
        '--realpath' => true,
    ])->assertExitCode(0);

    $centralPost = Post::create(['title' => 'central post']);
    $centralComment = $centralPost->comments()->create(['comment' => 'central comment']);

    /**
     * @var Route $tenantRoute
     * @var Route $commentTenantRoute
     *
     * The Route instance is always assigned to this variable
     */
    $tenantRoute = null;
    $commentTenantRoute = null;
    $tenantRouteMiddleware = ['tenant', 'web'];

    // If defaulting to tenant routes
    // With kernel identification, we make the tenant route have no MW (except 'web')
    // And with route-level identification, we make the route have only the identification middleware + 'web'
    if ($defaultRouteMode === RouteMode::TENANT) {
        $tenantRouteMiddleware = $useKernelIdentification ? ['web'] : [$identificationMiddleware, 'web'];
    }

    RouteFacade::group([
        'middleware' => $tenantRouteMiddleware,
        'prefix' => '/{tenant}',
    ], function () use ($controller, &$tenantRoute, &$commentTenantRoute) {
        $tenantRoute = RouteFacade::get('/tenant-route', [$controller, 'index'])->name('tenant-route');
        $commentTenantRoute = RouteFacade::get('/{post}/comment/{comment}/edit', [$controller, 'computePost'])->name('comment-tenant-route');
    });

    RouteFacade::group([
        'middleware' => ['central', 'web'],
    ], function () use ($controller) {
        RouteFacade::get('/central/home', function () {
            return 'central-home';
        });
        RouteFacade::get('/{post}/edit', [$controller, 'computePost']);
        RouteFacade::get('/{post}/comment/{comment}/edit', [$controller, 'computePost']);
    });

    $tenant = Tenant::create(['tenancy_db_name' => pest()->randomString()]);

    // Migrate users and comments tables on tenant connection
    pest()->artisan('tenants:migrate', [
        '--path' => __DIR__ . '/Etc/EarlyIdentification/path/migrations',
        '--realpath' => true,
    ])->assertExitCode(0);

    tenancy()->initialize($tenant);
    $tenantPost = Post::create(['title' => 'tenant post']);
    $tenantComment = $tenantPost->comments()->create(['comment' => 'tenant comment']);
    tenancy()->end();

    // Central routes are accessible and tenancy doesn't get initialized in early identification when the routes get accessed
    pest()->get('/central/home')->assertOk();
    pest()->get("/{$centralPost->id}/edit")->assertOk()->assertContent('central post');
    pest()->get("/{$centralPost->id}/comment/{$centralComment->id}/edit")->assertOk()->assertContent($centralPost->title . '-' . $centralComment->comment);
    assertTenancyInitializedInEarlyIdentificationRequest(false);

    // Tenant routes are accessible and tenancy gets initialized in early identification when the routes get accessed
    pest()->get("/{$tenant->id}/{$tenantPost->id}/comment/{$tenantComment->id}/edit")
        ->assertOk()
        ->assertContent($tenantPost->title . '-' . $tenantComment->comment);
    assertTenancyInitializedInEarlyIdentificationRequest();

    // Tenant routes that use path identification receive the tenant parameter automatically
    // (setDefaultTenantForRouteParametersWhenInitializingTenancy() in Stancl\Tenancy\Middleware\InitializeTenancyByPath)
    expect(route('tenant-route'))->toBe(route('tenant-route', ['tenant' => $tenant->getTenantKey()]));
})->with([
    'route-level identification' => false,
    'kernel identification' => true,
// Creates a matrix (multiple with())
])->with([
    'default to tenant routes' => RouteMode::TENANT,
    'default to central routes' => RouteMode::CENTRAL,
]);

test('early identification works with request data identification', function (string $type, bool $useKernelIdentification, RouteMode $defaultRouteMode) {
    $identificationMiddleware = InitializeTenancyByRequestData::class;

    if ($useKernelIdentification) {
        $controller = ControllerWithMiddleware::class;
        app(Kernel::class)->pushMiddleware($identificationMiddleware);
    } else {
        $controller = ControllerWithRouteMiddleware::class;
        RouteFacade::middlewareGroup('tenant', [$identificationMiddleware]);
    }

    config(['tenancy.default_route_mode' => $defaultRouteMode]);

    $tenantRouteMiddleware = 'tenant';

    // If defaulting to tenant routes
    // With kernel identification, we make the tenant route have no MW
    // And with route-level identification, we make the route have only the identification middleware
    if ($defaultRouteMode === RouteMode::TENANT) {
        $tenantRouteMiddleware = $useKernelIdentification ? null : $identificationMiddleware;
    }

    RouteFacade::get('/tenant-route', [$controller, 'index'])->middleware($tenantRouteMiddleware);
    RouteFacade::get('/central-route', fn () => 'central route')->middleware($defaultRouteMode === RouteMode::TENANT ? 'central' : null);

    $tenantKey = Tenant::create()->getTenantKey();

    // Central route is accessible for every $type
    pest()->get('/central-route')->assertOk()->assertContent('central route');

    $response = match ($type) {
        'header' => pest()->get('/tenant-route', ['X-Tenant' => $tenantKey]),
        'queryParameter' => pest()->get("/tenant-route?tenant={$tenantKey}"),
        'cookie' => pest()->withUnencryptedCookie('X-Tenant', $tenantKey)
            ->get('/tenant-route'),
    };

    $response->assertOk()->assertSee('token:' . $tenantKey);
})->with([
    'using request header parameter' => 'header',
    'using request query parameter' => 'queryParameter',
    'using request cookie parameter' => 'cookie',
// Creates a matrix (multiple with())
])->with([
    'route-level identification' => false,
    'kernel identification' => true,
])->with([
    'default to tenant routes' => RouteMode::TENANT,
    'default to central routes' => RouteMode::CENTRAL,
]);

test('early identification works with domain identification', function (string $middleware, string $domain, bool $useKernelIdentification, RouteMode $defaultRouteMode) {
    config(['tenancy.default_route_mode' => $defaultRouteMode]);

    if ($useKernelIdentification) {
        $controller = ControllerWithMiddleware::class;
        app(Kernel::class)->pushMiddleware($middleware);
        app(Kernel::class)->pushMiddleware(PreventAccessFromUnwantedDomains::class);
    } else {
        $controller = ControllerWithRouteMiddleware::class;
        RouteFacade::middlewareGroup('tenant', [$middleware, PreventAccessFromUnwantedDomains::class]);
    }

    // Tenant route
    $tenantRoute = RouteFacade::get('/tenant-route', [$controller, 'index']);

    // Central route
    $centralRoute = RouteFacade::get('/central-route', function () {
        return 'central route';
    });

    $defaultToTenantRoutes = $defaultRouteMode === RouteMode::TENANT;

    // Test defaulting to route mode (central/tenant context)
    if ($useKernelIdentification) {
        $routeThatShouldReceiveMiddleware = $defaultToTenantRoutes ? $centralRoute : $tenantRoute;
        $routeThatShouldReceiveMiddleware->middleware($defaultToTenantRoutes ? 'central' : 'tenant');
    } elseif (! $defaultToTenantRoutes) {
        $tenantRoute->middleware('tenant');
    } else {
        // Route-level identification + defaulting to tenant routes
        // We still have to apply the tenant middleware to the routes, so they aren't really tenant by default
        $tenantRoute->middleware([$middleware, PreventAccessFromUnwantedDomains::class]);
    }

    $tenant = Tenant::create();

    $tenant->domains()->create([
        'domain' => $domain,
    ]);

    if ($domain === 'foo') {
        $domain = 'foo.localhost';
    }

    pest()->get('http://localhost/central-route')->assertOk()->assertContent('central route'); // Central route is accessible

    $response = pest()->get("http://{$domain}/tenant-route");

    if ($defaultToTenantRoutes === $useKernelIdentification || $useKernelIdentification) {
        $response->assertOk();
        assertTenancyInitializedInEarlyIdentificationRequest();
    } elseif (! $defaultToTenantRoutes) {
        $response->assertNotFound();
        assertTenancyInitializedInEarlyIdentificationRequest(false);
    }

    // Expect tenancy is initialized (or not) for the right tenant at the tenant route
    expect($response->getContent())->toBe('token:' . tenant()->getTenantKey());
})->with([
    'domain identification' => ['middleware' => InitializeTenancyByDomain::class, 'domain' => 'foo.test'],
    'subdomain identification' => ['middleware' => InitializeTenancyBySubdomain::class, 'domain' => 'foo'],
    'domainOrSubdomain identification using domain' => ['middleware' => InitializeTenancyByDomainOrSubdomain::class, 'domain' => 'foo.test'],
    'domainOrSubdomain identification using subdomain' => ['middleware' => InitializeTenancyByDomainOrSubdomain::class, 'domain' => 'foo'],
// Creates a matrix (multiple with())
])->with([
    'route-level identification' => false,
    'kernel identification' => true,
])->with([
    'default to tenant routes' => RouteMode::TENANT,
    'default to central routes' => RouteMode::CENTRAL,
]);

test('the tenant parameter is only removed from tenant routes when using path identification', function (bool $kernelIdentification, bool $pathIdentification) {
    if ($kernelIdentification) {
        $middleware = $pathIdentification ? InitializeTenancyByPath::class : InitializeTenancyByDomain::class;

        app(Kernel::class)->pushMiddleware($middleware);

        RouteFacade::get('/{tenant}/central-route', [ControllerWithMiddleware::class, 'routeHasTenantParameter'])
            ->middleware('central')
            ->name('central-route');

        RouteFacade::get('/{tenant}/tenant-route', [ControllerWithMiddleware::class, 'routeHasTenantParameter'])
            ->middleware('tenant')
            ->name('tenant-route');

        RouteFacade::get($pathIdentification ? '/universal-route' : '/universal-route/{tenant?}', [ControllerWithMiddleware::class, 'routeHasTenantParameter'])
            ->middleware('universal')
            ->name('universal-route');

        /** @var CloneRoutesAsTenant */
        $cloneRoutesAction = app(CloneRoutesAsTenant::class);
        $cloneRoutesAction->handle();

        $tenant = Tenant::create();
        $tenantKey = $tenant->getTenantKey();

        // Expect route to receive the tenant parameter
        $response = pest()->get($tenantKey . '/central-route')->assertOk();
        expect((bool) $response->getContent())->toBeTrue();

        if ($pathIdentification) {
            // Tenant parameter is removed from tenant routes using kernel path identification (Stancl\Tenancy\Listeners\ForgetTenantParameter)
            $response = pest()->get($tenantKey . '/tenant-route')->assertOk();
            expect((bool) $response->getContent())->toBeFalse();

            // The tenant parameter gets removed from the cloned universal route
            $response = pest()->get($tenantKey . '/universal-route')->assertOk();
            expect((bool) $response->getContent())->toBeFalse();
        } else {
            // Tenant parameter is not removed from tenant routes using other kernel identification MW
            $tenant->domains()->create(['domain' => $domain = $tenantKey . '.localhost']);

            $response = pest()->get("http://{$domain}/{$tenantKey}/tenant-route")->assertOk();
            expect((bool) $response->getContent())->toBeTrue();

            // The tenant parameter does not get removed from the universal route when accessing it through the central domain
            $response = pest()->get("http://localhost/universal-route/$tenantKey")->assertOk();
            expect((bool) $response->getContent())->toBeTrue();

            // The tenant parameter gets removed from the universal route when accessing it through the tenant domain
            $response = pest()->get("http://{$domain}/universal-route")->assertOk();
            expect((bool) $response->getContent())->toBeFalse();
        }
    } else {
        RouteFacade::middlewareGroup('tenant', [$pathIdentification ? InitializeTenancyByPath::class : InitializeTenancyByDomain::class]);

        // Route-level identification
        RouteFacade::get('/{tenant}/central-route', [ControllerWithMiddleware::class, 'routeHasTenantParameter'])
            ->middleware('central')
            ->name('central-route');

        RouteFacade::get('/{tenant}/tenant-route', [ControllerWithMiddleware::class, 'routeHasTenantParameter'])
            ->middleware('tenant')
            ->name('tenant-route');

        $tenant = Tenant::create();
        $tenantKey = $tenant->getTenantKey();

        if ($pathIdentification) {
            // Tenant parameter isn't removed from central routes
            $response = pest()->get("http://localhost/{$tenantKey}/central-route")->assertOk();
            expect((bool) $response->getContent())->toBeTrue();

            // Tenant parameter is removed from tenant routes that are using kernel path identification (in PathTenantResolver)
            $response = pest()->get("http://localhost/{$tenantKey}/tenant-route")->assertOk();
            expect((bool) $response->getContent())->toBeFalse();
        } else {
            $tenant->domains()->create(['domain' => $domain = $tenantKey . '.localhost']);

            // Tenant parameter is not removed from tenant routes that are using other identification MW
            $response = pest()->get("http://{$domain}/{$tenantKey}/tenant-route")->assertOk();
            expect((bool) $response->getContent())->toBeTrue();
        }
    }
})->with([
    'kernel path identification' => ['kernelIdentification' => true, 'pathIdentification' => true],
    'route-level path identification' => ['kernelIdentification' => false, 'pathIdentification' => true],
    'kernel domain identification' => ['kernelIdentification' => true, 'pathIdentification' => false],
    'route-level domain identification' => ['kernelIdentification' => false, 'pathIdentification' => false],
]);

test('route level identification is prioritized over kernel identification', function (
    string|array $kernelIdentificationMiddleware,
    string|array $routeIdentificationMiddleware,
    string $routeUri,
    string $domainToVisit,
    string|null $domain = null,
    RouteMode $defaultRouteMode,
) {
    $tenant = Tenant::create();
    $domainToVisit = str_replace('{tenantKey}', $tenant->getTenantKey(), $domainToVisit);

    config(['tenancy.default_route_mode' => $defaultRouteMode]);

    if ($domain) {
        $tenant->domains()->create(['domain' => str_replace('{tenantKey}', $tenant->getTenantKey(), $domain)]);
    }

    foreach (Arr::wrap($kernelIdentificationMiddleware) as $identificationMiddleware) {
        app(Kernel::class)->pushMiddleware($identificationMiddleware);
    }

    // We're testing *non-early* route-level identification so that we can assert that early kernel identification got skipped
    // Also, ignore the defaulting when the identification MW is applied directly on the route
    // The route is automatically considered tenant if it has identification middleware (unless it also has the 'universal' middleware)
    RouteFacade::get($routeUri, [ControllerWithMiddleware::class, 'index'])->middleware($routeIdentificationMiddleware);

    pest()->get($domainToVisit)->assertOk();

    // Kernel (early) identification skipped
    expect(app()->make('controllerRunsInTenantContext'))->toBeFalse();
})->with([
    'kernel request data identification mw' => ['kernelMiddleware' => InitializeTenancyByRequestData::class],
    'kernel path identification mw' => ['kernelMiddleware' => InitializeTenancyByPath::class],
    'kernel domain identification mw' => ['kernelMiddleware' => [PreventAccessFromUnwantedDomains::class, InitializeTenancyByDomain::class]],
    'kernel subdomain identification mw' => ['kernelMiddleware' => [PreventAccessFromUnwantedDomains::class, InitializeTenancyBySubdomain::class]],
    'kernel domainOrSubdomain identification mw using domain' => ['kernelMiddleware' => [PreventAccessFromUnwantedDomains::class, InitializeTenancyByDomainOrSubdomain::class]],
    'kernel domainOrSubdomain identification mw using subdomain' => ['kernelMiddleware' => [PreventAccessFromUnwantedDomains::class, InitializeTenancyByDomainOrSubdomain::class]],
// Creates a matrix (multiple with())
])->with([
    'route level request data identification mw' => ['routeLevelMiddleware' => InitializeTenancyByRequestData::class, 'routeUri' => '/tenant-route', 'domainToVisit' => 'http://localhost/tenant-route?tenant={tenantKey}', 'domain' => null],
    'route level path identification mw' => ['routeLevelMiddleware' => InitializeTenancyByPath::class, 'routeUri' => '/{tenant}/tenant-route', 'domainToVisit' => 'http://localhost/{tenantKey}/tenant-route', 'domain' => null],
    'route level domain identification mw' => ['routeLevelMiddleware' => [PreventAccessFromUnwantedDomains::class, InitializeTenancyByDomain::class], 'routeUri' => '/tenant-route', 'domainToVisit' => 'http://{tenantKey}.test/tenant-route', 'domain' => '{tenantKey}.test'],
    'route level subdomain identification mw' => ['routeLevelMiddleware' => [PreventAccessFromUnwantedDomains::class, InitializeTenancyBySubdomain::class], 'routeUri' => '/tenant-route', 'domainToVisit' => 'http://{tenantKey}.localhost/tenant-route', 'domain' => '{tenantKey}'],
    'route level domainOrSubdomain identification mw using domain' => ['routeLevelMiddleware' => [PreventAccessFromUnwantedDomains::class, InitializeTenancyByDomainOrSubdomain::class], 'routeUri' => '/tenant-route', 'domainToVisit' => 'http://{tenantKey}.test/tenant-route', 'domain' => '{tenantKey}.test'],
    'route level domainOrSubdomain identification mw using subdomain' => ['routeLevelMiddleware' => [PreventAccessFromUnwantedDomains::class, InitializeTenancyByDomainOrSubdomain::class], 'routeUri' => '/tenant-route', 'domainToVisit' => 'http://{tenantKey}.localhost/tenant-route', 'domain' => '{tenantKey}'],
])
->with([
    'default to tenant routes' => RouteMode::TENANT,
    'default to central routes' => RouteMode::CENTRAL,
]);

test('routes with path identification middleware can get prefixed using the clone action', function() {
    $tenantKey = Tenant::create()->getTenantKey();

    RouteFacade::get('/home', fn () => tenant()?->getTenantKey())->name('home')->middleware(InitializeTenancyByPath::class);

    pest()->get("http://localhost/$tenantKey/home")->assertNotFound();

    app(CloneRoutesAsTenant::class)->handle();

    pest()->get("http://localhost/$tenantKey/home")->assertOk();
});

function assertTenancyInitializedInEarlyIdentificationRequest(bool $expect = true): void
{
    expect(app()->make('additionalMiddlewareRunsInTenantContext'))->toBe($expect); // Assert that middleware added in the controller constructor runs in tenant context
    expect(app()->make('controllerRunsInTenantContext'))->toBe($expect); // Assert that tenancy is initialized in the controller constructor
}
