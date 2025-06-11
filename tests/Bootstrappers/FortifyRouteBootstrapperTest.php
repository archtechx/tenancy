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

    config([
        // Parameter name (RequestDataTenantResolver::queryParameterName())
        'tenancy.identification.resolvers.' . RequestDataTenantResolver::class . '.query_parameter' => 'team_query',
        // Parameter value (RequestDataTenantResolver::payloadValue() gets the tenant model column value)
        'tenancy.identification.resolvers.' . RequestDataTenantResolver::class . '.tenant_model_column' => 'company',
    ]);

    config([
        // Parameter name (PathTenantResolver::tenantParameterName())
        'tenancy.identification.resolvers.' . PathTenantResolver::class . '.tenant_parameter_name' => 'team_path',
        // Parameter value (PathTenantResolver::tenantParameterValue() gets the tenant model column value)
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

    /**
     * When $passQueryParameter is true, the bootstrapper uses
     * the RequestDataTenantResolver config for generating the Fortify URLs
     * - tenant parameter is 'team_query'
     * - parameter value is the tenant's company ('Acme')
     *
     * When $passQueryParameter is false, the bootstrapper uses
     * the PathTenantResolver config for generating the Fortify URLs
     * - tenant parameter is 'team_path'
     * - parameter value is the tenant's name ('Foo')
     */
    $tenant = Tenant::create([
        'company' => 'Acme',
        'name' => 'Foo',
    ]);

    // The bootstrapper generates and overrides the URLs in the Fortify config correctly
    // (= the generated URLs have the correct tenant parameter + parameter value)
    // The bootstrapper should use RequestDataTenantResolver while generating the URLs (default)
    FortifyRouteBootstrapper::$passQueryParameter = true;

    tenancy()->initialize($tenant);

    expect(config('fortify.home'))->toBe('http://localhost/home?team_query=Acme');
    expect(config('fortify.redirects'))->toBe(['login' => 'http://localhost/welcome?team_query=Acme']);

    // The bootstrapper restores the original Fortify config when ending tenancy
    tenancy()->end();

    expect(config('fortify.home'))->toBe($originalFortifyHome);
    expect(config('fortify.redirects'))->toBe($originalFortifyRedirects);

    // The bootstrapper should use PathTenantResolver while generating the URLs now
    FortifyRouteBootstrapper::$passQueryParameter = false;

    tenancy()->initialize($tenant);

    expect(config('fortify.home'))->toBe('http://localhost/home?team_path=Foo');
    expect(config('fortify.redirects'))->toBe(['login' => 'http://localhost/welcome?team_path=Foo']);

    tenancy()->end();

    // The bootstrapper can override the home and redirects config without the tenant parameter being passed
    FortifyRouteBootstrapper::$passTenantParameter = false;

    tenancy()->initialize($tenant);

    expect(config('fortify.home'))->toBe('http://localhost/home');
    expect(config('fortify.redirects'))->toBe(['login' => 'http://localhost/welcome']);
});
