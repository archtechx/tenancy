<?php

use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Route;
use Stancl\Tenancy\Bootstrappers\Integrations\FortifyRouteBootstrapper;
use Stancl\Tenancy\Enums\Context;
use Stancl\Tenancy\Events\TenancyEnded;
use Stancl\Tenancy\Events\TenancyInitialized;
use Stancl\Tenancy\Listeners\BootstrapTenancy;
use Stancl\Tenancy\Listeners\RevertToCentralContext;

beforeEach(function () {
    Event::listen(TenancyInitialized::class, BootstrapTenancy::class);
    Event::listen(TenancyEnded::class, RevertToCentralContext::class);
});

test('fortify route tenancy bootstrapper updates fortify config correctly', function() {
    config(['tenancy.bootstrappers' => [FortifyRouteBootstrapper::class]]);

    $originalFortifyHome = config('fortify.home');
    $originalFortifyRedirects = config('fortify.redirects');

    Route::get('/home', function () {
        return true;
    })->name($homeRouteName = 'home');

    Route::get('/{tenant}/home', function () {
        return true;
    })->name($pathIdHomeRouteName = 'tenant.home');

    Route::get('/welcome', function () {
        return true;
    })->name($welcomeRouteName = 'welcome');

    Route::get('/{tenant}/welcome', function () {
        return true;
    })->name($pathIdWelcomeRouteName = 'path.welcome');

    FortifyRouteBootstrapper::$fortifyHome = $homeRouteName;

    // Make login redirect to the central welcome route
    FortifyRouteBootstrapper::$fortifyRedirectMap['login'] = [
        'route_name' => $welcomeRouteName,
        'context' => Context::CENTRAL,
    ];

    tenancy()->initialize($tenant = Tenant::create());
    // The bootstraper makes fortify.home always receive the tenant parameter
    expect(config('fortify.home'))->toBe('http://localhost/home?tenant=' . $tenant->getTenantKey());

    // The login redirect route has the central context specified, so it doesn't receive the tenant parameter
    expect(config('fortify.redirects'))->toEqual(['login' => 'http://localhost/welcome']);

    tenancy()->end();
    expect(config('fortify.home'))->toBe($originalFortifyHome);
    expect(config('fortify.redirects'))->toBe($originalFortifyRedirects);

    // Making a route's context will pass the tenant parameter to the route
    FortifyRouteBootstrapper::$fortifyRedirectMap['login']['context'] = Context::TENANT;

    tenancy()->initialize($tenant);

    expect(config('fortify.redirects'))->toEqual(['login' => 'http://localhost/welcome?tenant=' . $tenant->getTenantKey()]);

    // Make the home and login route accept the tenant as a route parameter
    // To confirm that tenant route parameter gets filled automatically too (path identification works as well as query string)
    FortifyRouteBootstrapper::$fortifyHome = $pathIdHomeRouteName;
    FortifyRouteBootstrapper::$fortifyRedirectMap['login']['route_name'] = $pathIdWelcomeRouteName;

    tenancy()->end();

    tenancy()->initialize($tenant);

    expect(config('fortify.home'))->toBe("http://localhost/{$tenant->getTenantKey()}/home");
    expect(config('fortify.redirects'))->toEqual(['login' => "http://localhost/{$tenant->getTenantKey()}/welcome"]);
});
