<?php

use Stancl\Tenancy\Tests\Etc\Tenant;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Route;
use Stancl\Tenancy\Events\TenancyEnded;
use Stancl\Tenancy\Events\TenancyInitialized;
use Stancl\Tenancy\Listeners\BootstrapTenancy;
use Stancl\Tenancy\Listeners\RevertToCentralContext;
use Stancl\Tenancy\Bootstrappers\Integrations\FortifyRouteBootstrapper;
use Stancl\Tenancy\Resolvers\RequestDataTenantResolver;
use Stancl\Tenancy\Resolvers\PathTenantResolver;

beforeEach(function () {
    Event::listen(TenancyInitialized::class, BootstrapTenancy::class);
    Event::listen(TenancyEnded::class, RevertToCentralContext::class);
    FortifyRouteBootstrapper::$passTenantParameter = true;
});

afterEach(function () {
    FortifyRouteBootstrapper::$passTenantParameter = true;
    FortifyRouteBootstrapper::$fortifyRedirectMap = [];
    FortifyRouteBootstrapper::$fortifyHome = 'tenant.dashboard';
    FortifyRouteBootstrapper::$passQueryParameter = false;
});

test('fortify route tenancy bootstrapper updates fortify config correctly', function() {
    config(['tenancy.bootstrappers' => [FortifyRouteBootstrapper::class]]);

    // Config used for generating Fortify route URLs
    // when FortifyRouteBootstrapper::$passQueryParameter is true (default)
    config([
        // Parameter name (RequestDataTenantResolver::queryParameterName())
        'tenancy.identification.resolvers.' . RequestDataTenantResolver::class . '.query_parameter' => 'team_query',
        // Parameter value (RequestDataTenantResolver::payloadValue())
        'tenancy.identification.resolvers.' . RequestDataTenantResolver::class . '.tenant_model_column' => 'company',
    ]);

    // Config used for generating Fortify route URLs
    // when FortifyRouteBootstrapper::$passQueryParameter is false
    config([
        // Parameter name (PathTenantResolver::tenantParameterName())
        'tenancy.identification.resolvers.' . PathTenantResolver::class . '.tenant_parameter_name' => 'team_path',
        // Parameter value (PathTenantResolver::tenantParameterValue())
        'tenancy.identification.resolvers.' . PathTenantResolver::class . '.tenant_model_column' => 'name',
    ]);

    $originalFortifyHome = config('fortify.home');
    $originalFortifyRedirects = config('fortify.redirects');

    Route::get('/home', fn () => true)->name($homeRouteName = 'home');
    Route::get('/welcome', fn () => true)->name($welcomeRouteName = 'welcome');

    FortifyRouteBootstrapper::$fortifyHome = $homeRouteName;
    FortifyRouteBootstrapper::$fortifyRedirectMap['login'] = $welcomeRouteName;

    expect(config('fortify.home'))->toBe($originalFortifyHome);
    expect(config('fortify.redirects'))->toBe($originalFortifyRedirects);

    $tenant = Tenant::create([
        'company' => 'Acme', // Tenant parameter value $passQueryParameter is true
        'name' => 'Foo', // Tenant parameter value when $passQueryParameter is false
    ]);

    // The bootstrapper overrides the URLs in the Fortify config correctly (the URLs have the correct tenant parameter + parameter value)
    // When $passQueryParameter is true, use the RequestDataTenantResolver config
    // - tenant parameter is 'team_query'
    // - parameter value is the tenant's company ('Acme')
    FortifyRouteBootstrapper::$passQueryParameter = true;

    tenancy()->initialize($tenant);

    expect(config('fortify.home'))->toBe('http://localhost/home?team_query=Acme');
    expect(config('fortify.redirects'))->toEqual(['login' => 'http://localhost/welcome?team_query=Acme']);

    // The bootstrapper restores the original Fortify config when ending tenancy
    tenancy()->end();

    expect(config('fortify.home'))->toBe($originalFortifyHome);
    expect(config('fortify.redirects'))->toBe($originalFortifyRedirects);

    // When $passQueryParameter is false, use the PathTenantResolver config
    // - tenant parameter is 'team_path'
    // - parameter value is the tenant's name ('Foo')
    FortifyRouteBootstrapper::$passQueryParameter = false;

    tenancy()->initialize($tenant);

    expect(config('fortify.home'))->toBe('http://localhost/home?team_path=Foo');
    expect(config('fortify.redirects'))->toEqual(['login' => 'http://localhost/welcome?team_path=Foo']);

    tenancy()->end();

    // The bootstrapper can override the home and redirects config without the tenant parameter being passed
    FortifyRouteBootstrapper::$passTenantParameter = false;

    tenancy()->initialize($tenant);

    expect(config('fortify.home'))->toBe('http://localhost/home')
        ->not()->toBe($originalFortifyHome);
    expect(config('fortify.redirects'))->toEqual(['login' => 'http://localhost/welcome'])
        ->not()->toBe($originalFortifyRedirects);
});
