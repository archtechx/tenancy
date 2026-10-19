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
use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Stancl\Tenancy\Database\TenantDatabaseManagers\MySQLDatabaseManager;
use Stancl\Tenancy\Database\TenantDatabaseManagers\SQLiteDatabaseManager;
use Stancl\Tenancy\Database\TenantDatabaseManagers\PostgreSQLDatabaseManager;
use Stancl\Tenancy\Database\TenantDatabaseManagers\PostgreSQLSchemaManager;

use function Stancl\Tenancy\Tests\pest;

afterEach($cleanup = function () {
    DatabaseTenancyBootstrapper::$harden = false;
    Tenant::$extraCustomColumns = [];
});

beforeEach(function () use ($cleanup) {
    Event::listen(TenancyInitialized::class, BootstrapTenancy::class);
    Event::listen(TenancyEnded::class, RevertToCentralContext::class);

    $cleanup();
});

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

test('harden prevents tenants from using the database of another tenant', function (bool $harden, string $connection, string $manager) {
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
        // Harden blocks initialization for tenants that use the database of another tenant
        expect(fn () => tenancy()->initialize($tenant))->toThrow(RuntimeException::class);

        // Connection should be reverted back to central
        $centralConnection = config('tenancy.database.central_connection');

        expect(DB::connection()->getName())->toBe($centralConnection);
    } else {
        expect(fn() => tenancy()->initialize($tenant))->not()->toThrow(Throwable::class);

        // Connection not reverted to central
        expect(DB::connection()->getName())->toBe('tenant');
    }
})->with([
    'hardening enabled' => true,
    'hardening disabled' => false,
])->with('db_managers');

test('database tenancy bootstrapper throws an exception if DB_URL is set', function (string|null $databaseUrl) {
    config(['tenancy.bootstrappers' => [DatabaseTenancyBootstrapper::class]]);

    Event::listen(TenantCreated::class, JobPipeline::make([CreateDatabase::class])->send(function (TenantCreated $event) {
        return $event->tenant;
    })->toListener());

    $tenant = Tenant::create();

    config(['database.connections.central.url' => $databaseUrl]);

    if ($databaseUrl) {
        expect(fn() => tenancy()->initialize($tenant))
            ->toThrow(Exception::class, 'The template connection must NOT have URL defined.');
    } else {
        expect(fn() => tenancy()->initialize($tenant))->not()->toThrow(Throwable::class);
    }
})->with(['abc.us-east-1.rds.amazonaws.com', null]);

// The tests below verify that anyone who can write tenant attributes (e.g. via mass assignment --
// the Tenant model is unguarded) cannot repoint the tenant connection using tenancy_db_* attributes.

test('tenancy_db_url cannot point the tenant connection at a different database server', function () {
    config(['tenancy.bootstrappers' => [DatabaseTenancyBootstrapper::class]]);

    Event::listen(TenantCreated::class, JobPipeline::make([CreateDatabase::class])->send(function (TenantCreated $event) {
        return $event->tenant;
    })->toListener());

    $tenant = Tenant::create();

    // mysql2 stands in for a server controlled by an attacker. Give it a database with
    // the tenant's name so that the testing-only "database exists" check passes too.
    config(['database.connections.mysql2' => array_merge(config('database.connections.central'), ['host' => 'mysql2', 'url' => null])]);
    DB::connection('mysql2')->statement("CREATE DATABASE `{$tenant->database()->getName()}`");
    DB::purge('mysql2');

    $centralHostname = DB::connection('central')->selectOne('SELECT @@hostname AS h')->h;

    $tenant->update(['tenancy_db_url' => 'mysql://root:password@mysql2:3306']);

    DatabaseTenancyBootstrapper::$harden = true;

    try {
        tenancy()->initialize($tenant);
    } catch (Throwable) {
        // Refusing to initialize tenancy is an acceptable outcome
    }

    if (DB::getDefaultConnection() === 'tenant') {
        expect(DB::selectOne('SELECT @@hostname AS h')->h)->toBe($centralHostname);
    } else {
        expect(DB::getDefaultConnection())->toBe(config('tenancy.database.central_connection'));
    }
});

test('harden prevents tenants from using the database of another tenant via connection config attributes', function (Closure $attributes) {
    config(['tenancy.bootstrappers' => [DatabaseTenancyBootstrapper::class]]);

    Event::listen(TenantCreated::class, JobPipeline::make([CreateDatabase::class])->send(function (TenantCreated $event) {
        return $event->tenant;
    })->toListener());

    $victim = Tenant::create();
    $victimDb = $victim->database()->getName();
    $attacker = Tenant::create();

    // Note that tenancy_db_name is left untouched, so the harden check looking for other
    // tenants with the same tenancy_db_name doesn't trigger
    $attacker->update($attributes($victimDb));

    DatabaseTenancyBootstrapper::$harden = true;

    try {
        tenancy()->initialize($attacker);
    } catch (Throwable) {
        // Refusing to initialize tenancy is an acceptable outcome
    }

    if (DB::getDefaultConnection() === 'tenant') {
        expect(DB::selectOne('SELECT DATABASE() AS db')->db)->not()->toBe($victimDb);
    } else {
        expect(DB::getDefaultConnection())->toBe(config('tenancy.database.central_connection'));
    }
})->with([
    'url' => fn (string $db) => ['tenancy_db_url' => "mysql://mysql/{$db}"],
    'read/write' => fn (string $db) => [
        'tenancy_db_read' => ['database' => $db],
        'tenancy_db_write' => ['database' => $db],
    ],
    // MySqlConnector interpolates isolation_level into a SET statement executed after "use `<tenant db>`"
    'isolation_level (SQL injection)' => fn (string $db) => [
        'tenancy_db_isolation_level' => "READ COMMITTED; USE `{$db}`",
    ],
]);

test('tenant connection config values are validated', function (array $attributes) {
    config(['tenancy.bootstrappers' => [DatabaseTenancyBootstrapper::class]]);

    Event::listen(TenantCreated::class, JobPipeline::make([CreateDatabase::class])->send(function (TenantCreated $event) {
        return $event->tenant;
    })->toListener());

    $tenant = Tenant::create();
    $tenant->update($attributes);

    expect(fn () => tenancy()->initialize($tenant))->toThrow(InvalidArgumentException::class);
    expect(DB::getDefaultConnection())->toBe(config('tenancy.database.central_connection'));
})->with([
    'host' => [['tenancy_db_host' => 'mysql;dbname=foo']],
    'port' => [['tenancy_db_port' => '3306;dbname=foo']],
    'charset' => [['tenancy_db_charset' => "utf8mb4'; DROP DATABASE foo; --"]],
    'collation' => [['tenancy_db_collation' => "utf8mb4_unicode_ci' --"]],
    'username' => [['tenancy_db_username' => 'root;']],
    'password' => [['tenancy_db_password' => "pass'word"]],
]);

test('harden prevents tenants from using the central database when a table prefix is set', function () {
    config(['tenancy.bootstrappers' => [DatabaseTenancyBootstrapper::class]]);

    Event::listen(TenantCreated::class, JobPipeline::make([CreateDatabase::class])->send(function (TenantCreated $event) {
        return $event->tenant;
    })->toListener());

    $tenant = Tenant::create();
    $centralDb = DB::connection('central')->getDatabaseName();

    // The harden check uses Schema::hasTable('tenants'), which applies the connection's table prefix
    $tenant->update(['tenancy_db_name' => $centralDb, 'tenancy_db_prefix' => 'x_']);

    DatabaseTenancyBootstrapper::$harden = true;

    try {
        tenancy()->initialize($tenant);
    } catch (Throwable) {
        // Expected
    }

    expect(DB::getDefaultConnection())->toBe(config('tenancy.database.central_connection'));
});

test('harden prevents tenants from using the database of another tenant when tenancy_db_name is a custom column', function () {
    config(['tenancy.bootstrappers' => [DatabaseTenancyBootstrapper::class]]);

    Tenant::$extraCustomColumns = ['tenancy_db_name'];

    Schema::table('tenants', function (Blueprint $table) {
        $table->string('tenancy_db_name')->nullable();
    });

    Event::listen(TenantCreated::class, JobPipeline::make([CreateDatabase::class])->send(function (TenantCreated $event) {
        return $event->tenant;
    })->toListener());

    $victim = Tenant::create();
    $attacker = Tenant::create();

    // The harden check only looks at the data->tenancy_db_name JSON path
    $attacker->update(['tenancy_db_name' => $victim->database()->getName()]);

    DatabaseTenancyBootstrapper::$harden = true;

    expect(fn () => tenancy()->initialize($attacker))->toThrow(RuntimeException::class);
    expect(DB::getDefaultConnection())->toBe(config('tenancy.database.central_connection'));
});

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
