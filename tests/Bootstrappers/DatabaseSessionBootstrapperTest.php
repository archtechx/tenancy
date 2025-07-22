<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Route;
use Stancl\JobPipeline\JobPipeline;
use Stancl\Tenancy\Bootstrappers\DatabaseTenancyBootstrapper;
use Stancl\Tenancy\Bootstrappers\DatabaseSessionBootstrapper;
use Stancl\Tenancy\Events;
use Stancl\Tenancy\Events\TenantCreated;
use Stancl\Tenancy\Jobs\CreateDatabase;
use Stancl\Tenancy\Listeners;
use Stancl\Tenancy\Middleware\InitializeTenancyByDomain;
use Stancl\Tenancy\Tests\Etc\Tenant;
use function Stancl\Tenancy\Tests\pest;

/**
 * This collection of regression tests verifies that SessionTenancyBootstrapper
 * fully fixes the issue described here https://github.com/archtechx/tenancy/issues/547
 *
 * This means: using the DB session driver and:
 *   1) switching to the central context from tenant requests, OR
 *   2) switching to the tenant context from central requests
 */

 beforeEach(function () {
    config(['session.driver' => 'database']);
    config(['tenancy.bootstrappers' => [DatabaseTenancyBootstrapper::class]]);

    Event::listen(
        TenantCreated::class,
        JobPipeline::make([CreateDatabase::class])->send(function (TenantCreated $event) {
            return $event->tenant;
        })->toListener()
    );

    Event::listen(Events\TenancyInitialized::class, Listeners\BootstrapTenancy::class);
    Event::listen(Events\TenancyEnded::class, Listeners\RevertToCentralContext::class);

    // Sessions table for central database
    pest()->artisan('migrate', [
        '--path' => __DIR__ . '/../Etc/session_migrations',
        '--realpath' => true,
    ])->assertExitCode(0);
 });

test('central helper can be used in tenant requests', function (bool $enabled, bool $shouldThrow) {
    if ($enabled) {
        config()->set(
            'tenancy.bootstrappers',
            array_merge(config('tenancy.bootstrappers'), [DatabaseSessionBootstrapper::class]),
        );
    }

    $tenant = Tenant::create();

    $tenant->domains()->create(['domain' => 'foo.localhost']);

    // run for tenants
    pest()->artisan('tenants:migrate', [
        '--path' => __DIR__ . '/../Etc/session_migrations',
        '--realpath' => true,
    ])->assertExitCode(0);

    Route::middleware(['web', InitializeTenancyByDomain::class])->get('/bar', function () {
        session(['message' => 'tenant session']);

        tenancy()->central(function () {
            return 'central results';
        });

        return session('message');
    });

    // We initialize tenancy before making the request, since sessions work a bit differently in tests
    // and we need the DB session handler to use the tenant connection (as it does in a real app on tenant requests).
    tenancy()->initialize($tenant);

    try {
        $this->withoutExceptionHandling()
            ->get('http://foo.localhost/bar')
            ->assertOk()
            ->assertSee('tenant session');

        if ($shouldThrow) {
            pest()->fail('Exception not thrown');
        }
    } catch (Throwable $e) {
        if ($shouldThrow) {
            pest()->assertTrue(true); // empty assertion to make the test pass
        } else {
            pest()->fail('Exception thrown: ' . $e->getMessage());
        }
    }
})->with([
    [
        false, // Disabled
        true // Should throw
    ],
    [
        true, // Enabled
        false // Should not throw
    ],
]);

test('tenant run helper can be used on central requests', function (bool $enabled, bool $shouldThrow) {
    if ($enabled) {
        config()->set(
            'tenancy.bootstrappers',
            array_merge(config('tenancy.bootstrappers'), [DatabaseSessionBootstrapper::class]),
        );
    }

    Tenant::create();

    // run for tenants
    pest()->artisan('tenants:migrate', [
        '--path' => __DIR__ . '/../Etc/session_migrations',
        '--realpath' => true,
    ])->assertExitCode(0);

    Route::middleware(['web'])->get('/bar', function () {
        session(['message' => 'central session']);

        Tenant::first()->run(function () {
            return 'tenant results';
        });

        return session('message');
    });

    try {
        $this->withoutExceptionHandling()
            ->get('http://localhost/bar')
            ->assertOk()
            ->assertSee('central session');

        if ($shouldThrow) {
            pest()->fail('Exception not thrown');
        }
    } catch (Throwable $e) {
        if ($shouldThrow) {
            pest()->assertTrue(true); // empty assertion to make the test pass
        } else {
            pest()->fail('Exception thrown: ' . $e->getMessage());
        }
    }
})->with([
    [
        false, // Disabled
        true // Should throw
    ],
    [
        true, // Enabled
        false // Should not throw
    ],
]);
