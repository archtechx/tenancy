<?php

use Illuminate\Support\Facades\Event;
use Stancl\JobPipeline\JobPipeline;
use Stancl\Tenancy\Bootstrappers\DatabaseTenancyBootstrapper;
use Stancl\Tenancy\Events\TenancyEnded;
use Stancl\Tenancy\Events\TenancyInitialized;
use Stancl\Tenancy\Jobs\CreateDatabase;
use Stancl\Tenancy\Events\TenantCreated;
use Stancl\Tenancy\Listeners\BootstrapTenancy;
use Stancl\Tenancy\Listeners\RevertToCentralContext;
use Stancl\Tenancy\Tests\Etc\Tenant;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\DB;
use Illuminate\Database\QueryException;

use function Stancl\Tenancy\Tests\pest;

$cleanup = function () {
    DatabaseTenancyBootstrapper::$harden = false;
};

beforeEach(function () use ($cleanup) {
    Event::listen(TenancyInitialized::class, BootstrapTenancy::class);
    Event::listen(TenancyEnded::class, RevertToCentralContext::class);

    $cleanup();
});

afterEach($cleanup);

test('harden prevents tenants from using the central database', function ($harden) {
    config([
        'tenancy.bootstrappers' => [DatabaseTenancyBootstrapper::class],
    ]);

    DatabaseTenancyBootstrapper::$harden = $harden;

    Event::listen(TenantCreated::class, JobPipeline::make([CreateDatabase::class])->send(function (TenantCreated $event) {
        return $event->tenant;
    })->toListener());

    $tenant = Tenant::create();

    $tenant->update([
        'tenancy_db_name' => config('database.connections.central.database'), // Central database name
    ]);

    if ($harden) {
        // Harden blocks initialization for tenants that use central database
        expect(fn () => tenancy()->initialize($tenant))->toThrow(RuntimeException::class);

        // Connection should be reverted back to central
        expect(DB::connection()->getName())->toBe('central');
    } else {
        expect(fn() => tenancy()->initialize($tenant))->not()->toThrow(Throwable::class);

        // Connection not reverted to central
        expect(DB::connection()->getName())->toBe('tenant');
    }
})->with([
    'hardening enabled' => true,
    'hardening disabled' => false,
]);

test('harden prevents tenants from using a database of another tenant', function ($harden) {
    config([
        'tenancy.bootstrappers' => [DatabaseTenancyBootstrapper::class],
    ]);

    DatabaseTenancyBootstrapper::$harden = $harden;

    Event::listen(TenantCreated::class, JobPipeline::make([CreateDatabase::class])->send(function (TenantCreated $event) {
        return $event->tenant;
    })->toListener());

    $tenant = Tenant::create();

    Tenant::create([
        'tenancy_db_name' => $tenantDbName = 'foo' . Str::random(8),
    ]);

    $tenant->update([
        'tenancy_db_name' => $tenantDbName, // Database of another tenant
    ]);

    if ($harden) {
        // Harden blocks initialization for tenants that use a database of another tenant
        expect(fn () => tenancy()->initialize($tenant))->toThrow(RuntimeException::class);

        // Connection should be reverted back to central
        expect(DB::connection()->getName())->toBe('central');
    } else {
        expect(fn() => tenancy()->initialize($tenant))->not()->toThrow(Throwable::class);

        // Connection not reverted to central
        expect(DB::connection()->getName())->toBe('tenant');
    }
})->with([
    'hardening enabled' => true,
    'hardening disabled' => false,
]);

test('database tenancy bootstrapper throws an exception if DATABASE_URL is set', function (string|null $databaseUrl) {
    config(['database.connections.central.url' => $databaseUrl]);

    config(['tenancy.bootstrappers' => [DatabaseTenancyBootstrapper::class]]);

    Event::listen(TenantCreated::class, JobPipeline::make([CreateDatabase::class])->send(function (TenantCreated $event) {
        return $event->tenant;
    })->toListener());

    if ($databaseUrl) {
        expect(fn() => Tenant::create())->toThrow(QueryException::class);
    } else {
        expect(function() {
            $tenant1 = Tenant::create();

            pest()->artisan('tenants:migrate');

            tenancy()->initialize($tenant1);
        })->not()->toThrow(Throwable::class);
    }
})->with(['abc.us-east-1.rds.amazonaws.com', null]);
