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
use Stancl\Tenancy\Exceptions\NotASubdomainException;
use Stancl\Tenancy\Middleware\InitializeTenancyByRequestData;
use Stancl\Tenancy\Tests\Etc\EarlyIdentification\Models\Post;
use Stancl\Tenancy\Middleware\PreventAccessFromUnwantedDomains;
use Stancl\Tenancy\Middleware\InitializeTenancyByDomainOrSubdomain;
use Stancl\Tenancy\Middleware\InitializeTenancyByOriginHeader;
use Stancl\Tenancy\Tests\Etc\EarlyIdentification\ControllerWithMiddleware;
use Stancl\Tenancy\Tests\Etc\EarlyIdentification\ControllerWithRouteMiddleware;
use Stancl\Tenancy\Exceptions\TenantCouldNotBeIdentifiedOnDomainException;
use function Stancl\Tenancy\Tests\pest;

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

dataset('identification_middleware', [
    InitializeTenancyByDomain::class,
    InitializeTenancyBySubdomain::class,
    InitializeTenancyByDomainOrSubdomain::class,
    InitializeTenancyByPath::class,
    InitializeTenancyByRequestData::class,
]);

dataset('domain_identification_middleware', [
    InitializeTenancyByDomain::class,
    InitializeTenancyBySubdomain::class,
    InitializeTenancyByDomainOrSubdomain::class,
]);

dataset('default_route_modes', [
    RouteMode::TENANT,
    RouteMode::CENTRAL,
]);

dataset('global_and_route_level_identification', [
    false, // Route-level identification
    true, // Global identification
]);

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
})->with('global_and_route_level_identification')
  ->with('default_route_modes');

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
        'cookie' => pest()->withUnencryptedCookie('tenant', $tenantKey)
            ->get('/tenant-route'),
    };

    $response->assertOk()->assertSee('token:' . $tenantKey);
})->with([
    'using request header parameter' => 'header',
    'using request query parameter' => 'queryParameter',
    'using request cookie parameter' => 'cookie',
])->with('global_and_route_level_identification')->with('default_route_modes');

test('early identification works with origin identification', function (bool $useKernelIdentification, RouteMode $defaultRouteMode) {
    $identificationMiddleware = InitializeTenancyByOriginHeader::class;

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

    RouteFacade::post('/tenant-route', [$controller, 'index'])->middleware($tenantRouteMiddleware);

    $tenant = Tenant::create();

    $tenant->domains()->create(['domain' => 'foo']);

    $tenantKey = $tenant->getTenantKey();

    $response = pest()->post('/tenant-route', headers: ['Origin' => 'foo.localhost']);

    $response->assertOk()->assertSee('token:' . $tenantKey);
})->with('global_and_route_level_identification')->with('default_route_modes');

test('early identification works with domain identification', function (string $middleware, bool $useKernelIdentification) {
    $tenant = Tenant::create();

    // Create domain and a subdomain for the tenant
    $tenant->createDomain('foo.test');
    $tenant->createDomain('foo');

    if ($useKernelIdentification) {
        app(Kernel::class)->pushMiddleware($middleware);
        app(Kernel::class)->pushMiddleware(PreventAccessFromUnwantedDomains::class);

        RouteFacade::get('/tenant-route', [ControllerWithMiddleware::class, 'index'])->middleware('tenant');
    } else {
        RouteFacade::get('/tenant-route', [ControllerWithRouteMiddleware::class, 'index'])->middleware([$middleware, PreventAccessFromUnwantedDomains::class]);
    }

    $domainUrl = 'http://foo.test/tenant-route';
    $subdomainUrl = str(config('app.url'))->replaceFirst('://', "://foo.")->toString() . '/tenant-route';

    $tenantUrls = Arr::wrap(match ($middleware) {
        InitializeTenancyByDomain::class => $domainUrl,
        InitializeTenancyBySubdomain::class => $subdomainUrl,
        InitializeTenancyByDomainOrSubdomain::class => [$domainUrl, $subdomainUrl], // Domain or subdomain -- try visiting both
    });

    foreach ($tenantUrls as $url) {
        $response = pest()->get($url);

        $response->assertOk();

        assertTenancyInitializedInEarlyIdentificationRequest();

        // Expect tenancy is initialized (or not) for the right tenant at the tenant route
        expect($response->getContent())->toBe('token:' . tenant()->getTenantKey());
    }
})->with('domain_identification_middleware')
  ->with('global_and_route_level_identification');

test('using different default route modes works with global domain identification', function(string $middleware, RouteMode $defaultRouteMode) {
    config(['tenancy.default_route_mode' => $defaultRouteMode]);

    $tenant = Tenant::create();

    // Create domain and a subdomain for the tenant
    $tenant->createDomain('foo.test');
    $tenant->createDomain('foo');

    // Create central and tenant routes, without any identification middleware or tags
    $centralRoute = RouteFacade::get('/central-route', fn () => 'central route');
    RouteFacade::get('/tenant-route', [ControllerWithMiddleware::class, 'index']);

    // Add the domain identification middleware to the kernel MW
    app(Kernel::class)->pushMiddleware($middleware);
    app(Kernel::class)->pushMiddleware(PreventAccessFromUnwantedDomains::class);

    $domainUrl = 'http://foo.test/tenant-route';
    $subdomainUrl = str(config('app.url'))->replaceFirst('://', "://foo.")->toString() . '/tenant-route';

    $tenantUrls = Arr::wrap(match ($middleware) {
        InitializeTenancyByDomain::class => $domainUrl,
        InitializeTenancyBySubdomain::class => $subdomainUrl,
        InitializeTenancyByDomainOrSubdomain::class => [$domainUrl, $subdomainUrl], // Domain or subdomain -- try visiting both
    });

    if ($defaultRouteMode === RouteMode::TENANT) {
        // When defaulting to tenant routes and using kernel identification,
        // the central route should not be accessible if not flagged as central.

        // Since central-route is considered tenant by default, and there's tenant ID MW,
        // expect that an exception specific to that ID MW to be thrown when trying to access the route.
        $exception = match ($middleware) {
            InitializeTenancyByDomain::class => TenantCouldNotBeIdentifiedOnDomainException::class,
            InitializeTenancyBySubdomain::class => NotASubdomainException::class,
            InitializeTenancyByDomainOrSubdomain::class => NotASubdomainException::class,
        };

        expect(fn () => $this->withoutExceptionHandling()->get('http://localhost/central-route'))->toThrow($exception);

        // Flagging the central route as central should make it accessible,
        // even if the default route mode is tenant
        $centralRoute = $centralRoute->middleware('central');

        pest()->get('http://localhost/central-route')->assertOk()->assertSee('central route');
    }

    foreach ($tenantUrls as $url) {
        $response = pest()->get($url);

        // If the default route mode is tenant, only the tenant route should be accessible
        // and tenancy should be initialized using early identification for the correct tenant
        if ($defaultRouteMode === RouteMode::TENANT) {
            $response->assertOk();

            assertTenancyInitializedInEarlyIdentificationRequest();

            // Expect tenancy is initialized for the right tenant at the tenant route
            expect($response->getContent())->toBe('token:' . tenant()->getTenantKey());
        } else {
            $response->assertNotFound();
        }
    }
})->with('domain_identification_middleware')
  ->with('default_route_modes');

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
    'kernel path identification' => [
        true, // Kernel identification
        true // Path identification
    ],
    'route-level path identification' => [
        false, // Kernel identification
        true // Path identification
    ],
    'kernel domain identification' => [
        true,
        false // Path identification
    ],
    'route-level domain identification' => [
        false, // Kernel identification
        false // Path identification
    ],
]);

test('route level domain identification is prioritized over kernel identification', function (
    string $kernelIdentificationMiddleware,
    string $routeIdentificationMiddleware,
    RouteMode $defaultRouteMode,
) {
    $tenant = Tenant::create();

    config(['tenancy.default_route_mode' => $defaultRouteMode]);

    // Subdomain
    $tenant->createDomain($subdomain = $tenant->getTenantKey());
    $tenant->createDomain($domain = $subdomain . '.test');

    app(Kernel::class)->pushMiddleware(PreventAccessFromUnwantedDomains::class)->pushMiddleware($kernelIdentificationMiddleware);

    // We're testing *non-early* route-level identification so that we can assert that early kernel identification got skipped
    // Also, ignore the defaulting when the identification MW is applied directly on the route
    // Because the route is automatically considered tenant if it has identification middleware (unless it also has the 'universal' middleware)
    RouteFacade::get('tenant-route', [ControllerWithMiddleware::class, 'index'])
        ->middleware([PreventAccessFromUnwantedDomains::class, $routeIdentificationMiddleware]);

    $domainIdUrl = "http://{$domain}/tenant-route";
    $subdomainIdUrl = str(config('app.url'))->replaceFirst('://', "://{$subdomain}.")->append("/tenant-route")->toString();

    $urlsToVisit = Arr::wrap(match ($routeIdentificationMiddleware) {
        InitializeTenancyByDomain::class => $domainIdUrl,
        InitializeTenancyBySubdomain::class => $subdomainIdUrl,
        InitializeTenancyByDomainOrSubdomain::class => [$domainIdUrl, $subdomainIdUrl], // Domain or subdomain -- try visiting both
    });

    foreach ($urlsToVisit as $url) {
        pest()->get($url)->assertOk();

        // Kernel (early) identification skipped
        expect(app()->make('controllerRunsInTenantContext'))->toBeFalse();
    }
})->with('identification_middleware')
  ->with('domain_identification_middleware')
  ->with('default_route_modes');

test('route level path and request data identification is prioritized over kernel identification', function (
    string $kernelIdentificationMiddleware,
    string $routeIdentificationMiddleware,
    RouteMode $defaultRouteMode,
) {
    $tenant = Tenant::create();
    config(['tenancy.default_route_mode' => $defaultRouteMode]);

    if (in_array($kernelIdentificationMiddleware, config('tenancy.identification.domain_identification_middleware'))) {
        // If a domain identification middleware is used, the prevent access MW is used too
        app(Kernel::class)->pushMiddleware(PreventAccessFromUnwantedDomains::class);
    }

    app(Kernel::class)->pushMiddleware($kernelIdentificationMiddleware);

    // We're testing *non-early* route-level identification so that we can assert that early kernel identification got skipped
    // Also, ignore the defaulting when the identification MW is applied directly on the route
    // The route is automatically considered tenant if it has identification middleware (unless it also has the 'universal' middleware)
    $route = RouteFacade::get('/tenant-route', [ControllerWithMiddleware::class, 'index'])->middleware($routeIdentificationMiddleware)->name('tenant-route');

    if ($routeIdentificationMiddleware === InitializeTenancyByPath::class) {
        $route = $route->prefix('{tenant}');
    }

    pest()->get(route('tenant-route', ['tenant' => $tenant->getTenantKey()]))->assertOk();

    // Kernel (early) identification skipped
    expect(app()->make('controllerRunsInTenantContext'))->toBeFalse();
})->with('identification_middleware')
  ->with([
    'route level request data identification' => InitializeTenancyByRequestData::class,
    'route level path identification' => InitializeTenancyByPath::class,
])->with('default_route_modes');

function assertTenancyInitializedInEarlyIdentificationRequest(bool $expect = true): void
{
    expect(app()->make('additionalMiddlewareRunsInTenantContext'))->toBe($expect); // Assert that middleware added in the controller constructor runs in tenant context
    expect(app()->make('controllerRunsInTenantContext'))->toBe($expect); // Assert that tenancy is initialized in the controller constructor
}
