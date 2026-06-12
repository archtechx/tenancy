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
use Stancl\Tenancy\Database\TenantDatabaseManagers\MySQLDatabaseManager;
use Stancl\Tenancy\Database\TenantDatabaseManagers\SQLiteDatabaseManager;
use Stancl\Tenancy\Database\TenantDatabaseManagers\PostgreSQLDatabaseManager;
use Stancl\Tenancy\Database\TenantDatabaseManagers\PostgreSQLSchemaManager;

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

test('harden prevents tenants from using the central database', function (bool $harden, string $connection, string $manager) {
    config([
        'tenancy.bootstrappers' => [DatabaseTenancyBootstrapper::class],
        "tenancy.database.managers.{$connection}" => $manager,
    ]);

    // Point the central connection at the tested connection's config and migrate it
    // (so that the central database/schema contains the tenants table).
    $centralConnection = config('tenancy.database.central_connection');
    $centralConfig = config("database.connections.{$connection}");

    if ($connection === 'sqlite') {
        $centralConfig['database'] = database_path($sqliteCentralDb = 'central.sqlite');
    }

    DB::purge($centralConnection);
    config(["database.connections.{$centralConnection}" => $centralConfig]);

    pest()->artisan('migrate:fresh', [
        '--force' => true,
        '--path' => __DIR__ . '/../../assets/migrations',
        '--realpath' => true,
    ]);

    DatabaseTenancyBootstrapper::$harden = $harden;

    Event::listen(TenantCreated::class, JobPipeline::make([CreateDatabase::class])->send(function (TenantCreated $event) {
        return $event->tenant;
    })->toListener());

    // Create the tenant with its own database, then repoint it at the central database/schema
    // (which contains the tenants table that the hardening check looks for).
    $tenant = Tenant::create(['tenancy_db_connection' => $connection]);

    $central = DB::connection($centralConnection);
    $centralName = match (true) {
        $manager === PostgreSQLSchemaManager::class => $central->selectOne('SELECT current_schema() AS schema')->schema, // Central schema name
        $connection === 'sqlite' => $sqliteCentralDb, // Central SQLite DB name
        default => $central->getDatabaseName(), // Central DB name
    };

    $tenant->update(['tenancy_db_name' => $centralName]);

    if ($harden) {
        // Harden blocks initialization for tenants that use the central database
        expect(fn () => tenancy()->initialize($tenant))->toThrow(RuntimeException::class);

        // Connection should be reverted back to central
        expect(DB::connection()->getName())->toBe($centralConnection);
    } else {
        expect(fn () => tenancy()->initialize($tenant))->not()->toThrow(Throwable::class);

        // Connection not reverted to central
        expect(DB::connection()->getName())->toBe('tenant');
    }
})->with([
    'hardening enabled' => true,
    'hardening disabled' => false,
])->with('db_managers');

test('harden prevents tenants from using a database of another tenant', function (bool $harden, string $connection, string $manager) {
    config([
        'tenancy.bootstrappers' => [DatabaseTenancyBootstrapper::class],
        "tenancy.database.managers.{$connection}" => $manager,
    ]);

    DatabaseTenancyBootstrapper::$harden = $harden;

    Event::listen(TenantCreated::class, JobPipeline::make([CreateDatabase::class])->send(function (TenantCreated $event) {
        return $event->tenant;
    })->toListener());

    $tenant = Tenant::create(['tenancy_db_connection' => $connection]);

    $dbName = Str::random(8) . ($connection === 'sqlite' ? '.sqlite' : '');

    Tenant::create(['tenancy_db_name' => $dbName, 'tenancy_db_connection' => $connection]);

    $tenant->update(['tenancy_db_name' => $dbName]);

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
])->with('db_managers');

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

// Database managers to test with hardening.
// Permission controlled managers omitted as they inherit the non-perm controlled managers (= they share the same code paths),
// each important code path is covered by testing the non-permission controlled manager, so adding permission controlled managers
// would add unnecessary complexity to the tests.
dataset('db_managers', [
    'mysql' => ['mysql', MySQLDatabaseManager::class],
    'pgsql (database)' => ['pgsql', PostgreSQLDatabaseManager::class],
    'pgsql (schema)' => ['pgsql', PostgreSQLSchemaManager::class],
    'sqlite' => ['sqlite', SQLiteDatabaseManager::class],
]);
