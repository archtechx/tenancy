<?php

use Illuminate\Support\Facades\Event;
use Stancl\JobPipeline\JobPipeline;
use Stancl\Tenancy\Bootstrappers\DatabaseTenancyBootstrapper;
use Stancl\Tenancy\Database\DatabaseConfig;
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

    // Since tenancy_db_name is a custom column, the harden check has to query the column instead of the data column's JSON
    $attacker->update(['tenancy_db_name' => $victim->database()->getName()]);

    DatabaseTenancyBootstrapper::$harden = true;

    expect(fn () => tenancy()->initialize($attacker))->toThrow(RuntimeException::class);
    expect(DB::getDefaultConnection())->toBe(config('tenancy.database.central_connection'));
});

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

// The tests below verify that tenancy_db_* attributes can only set allowed connection config keys, with validated values.
// Since the Tenant model is unguarded, anyone who can write tenant attributes (e.g. via mass assignment) could otherwise
// set keys like 'url', 'read' or 'write', or inject into DSNs or SQL executed on connect.

test('tenant database config keys that are not allowed prevent initialization', function () {
    config(['tenancy.bootstrappers' => [DatabaseTenancyBootstrapper::class]]);

    Event::listen(TenantCreated::class, JobPipeline::make([CreateDatabase::class])->send(function (TenantCreated $event) {
        return $event->tenant;
    })->toListener());

    $tenant = Tenant::create();
    $centralConnection = config('tenancy.database.central_connection');

    // Initialization works before any of the attributes are set
    tenancy()->initialize($tenant);
    expect(DB::getDefaultConnection())->toBe('tenant');
    tenancy()->end();

    $attributes = [
        // Overrides host, database and credentials, and can set any other key using the query string
        'url' => 'mysql://root:password@mysql2:3306/foo',
        // Merged over the top-level config, including the database name
        'read' => ['database' => 'foo'],
        'write' => ['database' => 'foo'],
        // MySqlConnector interpolates isolation_level into a SET statement executed after "use `<tenant db>`"
        'isolation_level' => 'READ COMMITTED; USE `foo`',
        // Would make the harden check's Schema::hasTable('tenants') look for a prefixed table
        'prefix' => 'x_',
        // PDO options, e.g. a command executed on connect
        'options' => [PDO::MYSQL_ATTR_INIT_COMMAND => 'USE foo'],
    ];

    foreach ($attributes as $key => $value) {
        $tenant->setAttribute("tenancy_db_{$key}", $value);

        expect(fn () => $tenant->run(fn () => null))->toThrow(InvalidArgumentException::class, "Tenant database config key '{$key}'");
        expect(DB::getDefaultConnection())->toBe($centralConnection);

        $tenant->offsetUnset("tenancy_db_{$key}");
    }
});

test('allowed tenant database config values are used in the tenant connection config', function () {
    $tenant = new Tenant([
        'id' => 'acme',
        'tenancy_db_host' => 'abc.us-east-1.rds.amazonaws.com',
        'tenancy_db_port' => 3307,
        'tenancy_db_charset' => 'utf8',
        'tenancy_db_collation' => 'utf8_unicode_ci',
        // Usernames and passwords aren't validated by default, so they can contain any characters
        'tenancy_db_username' => 'tenant.user@example',
        'tenancy_db_password' => "pa'ss\"word\\",
    ]);

    expect($tenant->database()->connection())->toMatchArray([
        'host' => 'abc.us-east-1.rds.amazonaws.com',
        'port' => 3307,
        'charset' => 'utf8',
        'collation' => 'utf8_unicode_ci',
        'username' => 'tenant.user@example',
        'password' => "pa'ss\"word\\",
    ]);
});

test('tenant database config keys can be allowed and validated', function () {
    $tenant = new Tenant(['id' => 'acme']);

    // Get the tenant connection config for the passed tenant database config, optionally configuring the allowed keys and validators
    $connection = function (array $config, Closure|null $configure = null) use ($tenant): array {
        $databaseConfig = new DatabaseConfig($tenant, $config);

        if ($configure) {
            $configure($databaseConfig);
        }

        return $databaseConfig->connection();
    };

    $invalidValue = fn (string $key) => "Invalid value of tenant database config key '{$key}' (set on tenant acme)";

    // Keys allowed by default use the default validation (username and password are covered by the test above)
    foreach ([
        'host' => 'mysql;dbname=foo',
        'port' => '3306;dbname=foo',
        'charset' => "utf8mb4'; DROP DATABASE foo; --",
        'collation' => "utf8mb4_unicode_ci' --",
    ] as $key => $value) {
        expect(fn () => $connection([$key => $value]))->toThrow(InvalidArgumentException::class, $invalidValue($key));
    }

    // Null values aren't validated since they unset the template connection's value
    expect($connection(['charset' => null]))->toMatchArray(['charset' => null]);

    // Allowed keys use the default validation
    $allowSsl = fn (DatabaseConfig $config) => $config->allow(['sslmode', 'sslcert']);
    expect(fn () => $connection(['sslmode' => 'require']))->toThrow(InvalidArgumentException::class, "Tenant database config key 'sslmode' (set on tenant acme");
    expect($connection(['sslmode' => 'require'], $allowSsl))->toMatchArray(['sslmode' => 'require']);
    expect(fn () => $connection(['sslmode' => 'require;host=foo'], $allowSsl))->toThrow(InvalidArgumentException::class, $invalidValue('sslmode'));

    // Validation can be turned off for multiple keys at once, regardless of the order of allow() and validate() calls
    $sslConfig = ['sslmode' => 'verify-full', 'sslcert' => '/etc/ssl/client.crt'];
    expect(fn () => $connection($sslConfig, $allowSsl))->toThrow(InvalidArgumentException::class, $invalidValue('sslcert'));
    expect($connection($sslConfig, fn (DatabaseConfig $config) => $config->allow(['sslmode', 'sslcert'])->validate(['sslmode', 'sslcert'], false)))->toMatchArray($sslConfig);
    expect($connection($sslConfig, fn (DatabaseConfig $config) => $config->validate(['sslmode', 'sslcert'], false)->allow(['sslmode', 'sslcert'])))->toMatchArray($sslConfig);

    // Custom validation
    $allowTimezone = fn (DatabaseConfig $config) => $config->allow('timezone')->validate('timezone', fn (mixed $value) => in_array($value, timezone_identifiers_list(), true));
    expect($connection(['timezone' => 'Europe/Prague'], $allowTimezone))->toMatchArray(['timezone' => 'Europe/Prague']);
    expect(fn () => $connection(['timezone' => "UTC'; DROP DATABASE foo; --"], $allowTimezone))->toThrow(InvalidArgumentException::class, $invalidValue('timezone'));

    // Allowing all keys still validates values using the default rules
    expect(fn () => $connection(['url' => 'mysql://foo'], fn (DatabaseConfig $config) => $config->allow('*')))->toThrow(InvalidArgumentException::class, $invalidValue('url'));

    // Turning off validation for all keys without their own validator
    $allowAnything = fn (DatabaseConfig $config) => $config->allow('*')->validate('*', false);
    expect($connection(['url' => 'mysql://foo', 'charset' => "utf8mb4' --"], $allowAnything))->toMatchArray(['url' => 'mysql://foo', 'charset' => "utf8mb4' --"]);
    expect(fn () => $connection(['host' => 'mysql;dbname=foo'], fn (DatabaseConfig $config) => $allowAnything($config)->validate('host')))->toThrow(InvalidArgumentException::class, $invalidValue('host'));
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
