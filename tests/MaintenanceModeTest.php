<?php

declare(strict_types=1);

use Illuminate\Foundation\Http\MaintenanceModeBypassCookie;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Exceptions;
use Stancl\Tenancy\Database\Concerns\MaintenanceMode;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\View;
use Stancl\Tenancy\Events\TenantMaintenanceModeDisabled;
use Stancl\Tenancy\Events\TenantMaintenanceModeEnabled;
use Stancl\Tenancy\Middleware\CheckTenantForMaintenanceMode;
use Stancl\Tenancy\Middleware\InitializeTenancyByDomain;
use function Stancl\Tenancy\Tests\pest;
use Stancl\Tenancy\Tests\Etc\Tenant;

beforeEach(function () {
    config(['tenancy.models.tenant' => MaintenanceTenant::class]);
});

test('tenants can be in maintenance mode', function () {
    Route::get('/foo', function () {
        return 'bar';
    })->middleware([InitializeTenancyByDomain::class, CheckTenantForMaintenanceMode::class]);

    $tenant = MaintenanceTenant::create();
    $tenant->domains()->create([
        'domain' => 'acme.localhost',
    ]);

    pest()->get('http://acme.localhost/foo')->assertStatus(200);

    $tenant->putDownForMaintenance();

    tenancy()->end(); // End tenancy before making a request
    pest()->get('http://acme.localhost/foo')->assertStatus(503);

    $tenant->bringUpFromMaintenance();

    tenancy()->end(); // End tenancy before making a request
    pest()->get('http://acme.localhost/foo')->assertStatus(200);
});

test('maintenance mode middleware can be used with universal routes', function () {
    Route::get('/foo', function () {
        return 'bar';
    })->middleware(['universal', InitializeTenancyByDomain::class, CheckTenantForMaintenanceMode::class]);

    $tenant = MaintenanceTenant::create();
    $tenant->domains()->create([
        'domain' => 'acme.localhost',
    ]);

    // Revert to central context after each request so that the tenant context
    // from the request doesn't persist
    $run = function (Closure $callback) { $callback(); tenancy()->end(); };

    $run(fn () => pest()->get('http://acme.localhost/foo')->assertStatus(200));
    $run(fn () => pest()->get('http://localhost/foo')->assertStatus(200));

    $tenant->putDownForMaintenance();

    $run(fn () => pest()->get('http://acme.localhost/foo')->assertStatus(503));
    $run(fn () => pest()->get('http://localhost/foo')->assertStatus(200)); // Not affected by a tenant's maintenance mode

    $tenant->bringUpFromMaintenance();

    $run(fn () => pest()->get('http://acme.localhost/foo')->assertStatus(200));
    $run(fn () => pest()->get('http://localhost/foo')->assertStatus(200));
});

test('maintenance mode events are fired', function () {
    $tenant = MaintenanceTenant::create();

    Event::fake([TenantMaintenanceModeEnabled::class, TenantMaintenanceModeDisabled::class]);

    $tenant->putDownForMaintenance();

    Event::assertDispatched(TenantMaintenanceModeEnabled::class);

    $tenant->bringUpFromMaintenance();

    Event::assertDispatched(TenantMaintenanceModeDisabled::class);
});

test('tenants can be put into maintenance mode using artisan commands', function() {
    Route::get('/foo', function () {
        return 'bar';
    })->middleware([InitializeTenancyByDomain::class, CheckTenantForMaintenanceMode::class]);

    $tenant = MaintenanceTenant::create();
    $tenant->domains()->create([
        'domain' => 'acme.localhost',
    ]);

    pest()->get('http://acme.localhost/foo')->assertStatus(200);

    pest()->artisan('tenants:down')
        ->expectsOutputToContain('Tenants are now in maintenance mode.')
        ->assertExitCode(0);

    Artisan::call('tenants:down');

    tenancy()->end(); // End tenancy before making a request
    pest()->get('http://acme.localhost/foo')->assertStatus(503);

    pest()->artisan('tenants:up')
        ->expectsOutputToContain('Tenants are now out of maintenance mode.')
        ->assertExitCode(0);

    tenancy()->end(); // End tenancy before making a request
    pest()->get('http://acme.localhost/foo')->assertStatus(200);
});

test('tenants:down can render a view during maintenance mode', function () {
    Route::get('/foo', function () {
        return 'bar';
    })->middleware([InitializeTenancyByDomain::class, CheckTenantForMaintenanceMode::class]);

    View::addLocation(__DIR__ . '/Etc/views');

    $tenant = MaintenanceTenant::create();
    $tenant->domains()->create([
        'domain' => 'acme.localhost',
    ]);

    pest()->artisan('tenants:down', ['--render' => 'maintenance', '--retry' => 60])->assertExitCode(0);

    // The view is rendered in the tenant's context
    tenancy()->end(); // End tenancy before making a request
    pest()->get('http://acme.localhost/foo')->assertStatus(503)->assertContent("{$tenant->id} is down, retry after 60");
});

test('laravel error views can be rendered during maintenance mode', function () {
    Route::get('/foo', function () {
        return 'bar';
    })->middleware([InitializeTenancyByDomain::class, CheckTenantForMaintenanceMode::class]);

    $tenant = MaintenanceTenant::create();
    $tenant->domains()->create([
        'domain' => 'acme.localhost',
    ]);

    // The default maintenance mode response is a 503 page, so a different error view is used to tell them apart
    $tenant->putDownForMaintenance(['render' => 'errors::404']);

    tenancy()->end(); // End tenancy before making a request
    pest()->get('http://acme.localhost/foo')->assertStatus(503)->assertSee('Not Found');
});

test('the default maintenance mode response is used if the view cannot be rendered', function () {
    Exceptions::fake();

    Route::get('/foo', function () {
        return 'bar';
    })->middleware([InitializeTenancyByDomain::class, CheckTenantForMaintenanceMode::class]);

    $tenant = MaintenanceTenant::create();
    $tenant->domains()->create([
        'domain' => 'acme.localhost',
    ]);

    $tenant->putDownForMaintenance(['render' => 'nonexistent']);

    tenancy()->end(); // End tenancy before making a request
    pest()->get('http://acme.localhost/foo')->assertStatus(503);

    Exceptions::assertReported(InvalidArgumentException::class);
});

test('paths excluded when putting tenants down are accessible during maintenance mode', function () {
    Route::get('/foo', function () {
        return 'bar';
    })->middleware([InitializeTenancyByDomain::class, CheckTenantForMaintenanceMode::class]);

    Route::get('/health', function () {
        return 'ok';
    })->middleware([InitializeTenancyByDomain::class, CheckTenantForMaintenanceMode::class]);

    $tenant = MaintenanceTenant::create();
    $tenant->domains()->create([
        'domain' => 'acme.localhost',
    ]);

    $tenant->putDownForMaintenance(['except' => ['/health']]);

    tenancy()->end(); // End tenancy before making a request
    pest()->get('http://acme.localhost/health')->assertStatus(200);

    tenancy()->end();
    pest()->get('http://acme.localhost/foo')->assertStatus(503);
});

test('maintenance mode can be bypassed using the secret', function () {
    Route::get('/foo', function () {
        return 'bar';
    })->middleware([InitializeTenancyByDomain::class, CheckTenantForMaintenanceMode::class]);

    $tenant = MaintenanceTenant::create();
    $tenant->domains()->create([
        'domain' => 'acme.localhost',
    ]);

    pest()->artisan('tenants:down', ['--secret' => 'letmein'])
        ->expectsOutputToContain('You may bypass maintenance mode by adding [?maintenance_secret=letmein] to a tenant URL.')
        ->assertExitCode(0);

    tenancy()->end(); // End tenancy before making a request
    pest()->get('http://acme.localhost/foo?maintenance_secret=letmeout')
        ->assertStatus(503)
        ->assertCookieMissing('laravel_maintenance');

    // The secret is removed from the URL, other query parameters are kept
    tenancy()->end();
    $cookie = pest()->get('http://acme.localhost/foo?maintenance_secret=letmein&page=2')
        ->assertRedirect('http://acme.localhost/foo?page=2')
        ->assertCookie('laravel_maintenance')
        ->getCookie('laravel_maintenance', false);

    tenancy()->end();
    pest()->withUnencryptedCookie('laravel_maintenance', $cookie->getValue())
        ->get('http://acme.localhost/foo')
        ->assertStatus(200);
});

test('an empty secret does not allow bypassing maintenance mode', function () {
    Route::get('/foo', function () {
        return 'bar';
    })->middleware([InitializeTenancyByDomain::class, CheckTenantForMaintenanceMode::class]);

    $tenant = MaintenanceTenant::create();
    $tenant->domains()->create([
        'domain' => 'acme.localhost',
    ]);

    pest()->artisan('tenants:down', ['--secret' => ''])
        ->doesntExpectOutputToContain('maintenance_secret')
        ->assertExitCode(0);

    // Previously, a cookie signed with an empty key was accepted
    tenancy()->end(); // End tenancy before making a request
    pest()->withUnencryptedCookie('laravel_maintenance', MaintenanceModeBypassCookie::create('')->getValue())
        ->get('http://acme.localhost/foo')
        ->assertStatus(503);
});

test('json requests get a json response instead of the maintenance redirect or view', function (array $data) {
    Route::get('/foo', function () {
        return 'bar';
    })->middleware([InitializeTenancyByDomain::class, CheckTenantForMaintenanceMode::class]);

    $tenant = MaintenanceTenant::create();
    $tenant->domains()->create([
        'domain' => 'acme.localhost',
    ]);

    $tenant->putDownForMaintenance($data);

    tenancy()->end(); // End tenancy before making a request
    pest()->getJson('http://acme.localhost/foo')
        ->assertStatus(503)
        ->assertJson(['message' => 'Service Unavailable']);
})->with([
    'redirect' => [['redirect' => '/maintenance']],
    'render' => [['render' => 'errors::503']],
]);

class MaintenanceTenant extends Tenant
{
    use MaintenanceMode;
}
