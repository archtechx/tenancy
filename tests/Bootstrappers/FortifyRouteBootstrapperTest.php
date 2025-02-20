<?php

use Stancl\Tenancy\Enums\Context;
use Stancl\Tenancy\Tests\Etc\Tenant;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Route;
use Stancl\Tenancy\Events\TenancyEnded;
use Stancl\Tenancy\Events\TenancyInitialized;
use Stancl\Tenancy\Listeners\BootstrapTenancy;
use Stancl\Tenancy\Listeners\RevertToCentralContext;
use Stancl\Tenancy\Bootstrappers\Integrations\FortifyRouteBootstrapper;

beforeEach(function () {
    Event::listen(TenancyInitialized::class, BootstrapTenancy::class);
    Event::listen(TenancyEnded::class, RevertToCentralContext::class);
    FortifyRouteBootstrapper::$passTenantParameter = true;
});

afterEach(function () {
    FortifyRouteBootstrapper::$passTenantParameter = true;
    FortifyRouteBootstrapper::$fortifyRedirectMap = [];
    FortifyRouteBootstrapper::$fortifyHome = 'tenant.dashboard';
    FortifyRouteBootstrapper::$defaultParameterNames = false;
});

test('fortify route tenancy bootstrapper updates fortify config correctly', function() {
    config(['tenancy.bootstrappers' => [FortifyRouteBootstrapper::class]]);

    $originalFortifyHome = config('fortify.home');
    $originalFortifyRedirects = config('fortify.redirects');

    Route::get('/home', function () {
        return true;
    })->name($homeRouteName = 'home');

    Route::get('/welcome', function () {
        return true;
    })->name($welcomeRouteName = 'welcome');

    FortifyRouteBootstrapper::$fortifyHome = $homeRouteName;
    FortifyRouteBootstrapper::$fortifyRedirectMap['login'] = $welcomeRouteName;

    expect(config('fortify.home'))->toBe($originalFortifyHome);
    expect(config('fortify.redirects'))->toBe($originalFortifyRedirects);

    FortifyRouteBootstrapper::$passTenantParameter = true;
    tenancy()->initialize($tenant = Tenant::create());
    expect(config('fortify.home'))->toBe('http://localhost/home?tenant=' . $tenant->getTenantKey());
    expect(config('fortify.redirects'))->toEqual(['login' => 'http://localhost/welcome?tenant=' . $tenant->getTenantKey()]);

    tenancy()->end();
    expect(config('fortify.home'))->toBe($originalFortifyHome);
    expect(config('fortify.redirects'))->toBe($originalFortifyRedirects);

    FortifyRouteBootstrapper::$passTenantParameter = false;
    tenancy()->initialize($tenant);
    expect(config('fortify.home'))->toBe('http://localhost/home');
    expect(config('fortify.redirects'))->toEqual(['login' => 'http://localhost/welcome']);

    tenancy()->end();
    expect(config('fortify.home'))->toBe($originalFortifyHome);
    expect(config('fortify.redirects'))->toBe($originalFortifyRedirects);
});
