<?php

use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Arr;
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

// Previously, the bootstrapper threw an exception when the template connection had a URL (DB_URL),
// since the URL's database name and credentials would take precedence over the tenant's.
test('tenant connection uses the tenant database when the template connection has a url', function () {
    config([
        'tenancy.bootstrappers' => [DatabaseTenancyBootstrapper::class],
        'database.connections.central.url' => 'mysql://root:password@mysql:3306/main',
    ]);

    Event::listen(TenantCreated::class, JobPipeline::make([CreateDatabase::class])->send(function (TenantCreated $event) {
        return $event->tenant;
    })->toListener());

    $tenant = Tenant::create();

    tenancy()->initialize($tenant);

    expect(DB::selectOne('SELECT DATABASE() AS name')->name)->toBe($tenant->database()->getName());
});

test('keys set in template_tenant_connection take precedence over the url of the central connection', function () {
    config([
        'database.connections.central.url' => 'mysql://root:password@mysql:3306/main',
        'tenancy.database.template_tenant_connection' => ['host' => 'mysql2'],
    ]);

    $tenant = new Tenant(['id' => 'acme']);

    // The username comes from the central connection's URL, the host from template_tenant_connection
    expect($tenant->database()->connection())
        ->not()->toHaveKey('url')
        ->toMatchArray(['host' => 'mysql2', 'username' => 'root', 'database' => $tenant->database()->getName()]);

    // A 'url' set in template_tenant_connection replaces the connection details of the central connection
    config(['tenancy.database.template_tenant_connection' => ['url' => 'mysql://foo:bar@mysql2:3306/main']]);

    expect($tenant->database()->connection())
        ->not()->toHaveKey('url')
        ->toMatchArray(['host' => 'mysql2', 'username' => 'foo', 'password' => 'bar', 'database' => $tenant->database()->getName()]);
});

// Laravel merges read, write and direct (pooled Postgres) configs over the top-level connection config when connecting.
// Previously, these could silently override the tenant's database name or its tenancy_db_* values.
test('values set for the tenant are applied to the read, write and direct configs of the template connection', function (array $template, array $tenantConfig, array $expected) {
    config(['tenancy.database.template_tenant_connection' => $template]);

    $tenant = new Tenant(array_merge(['id' => 'acme', 'tenancy_db_name' => 'acme_db'], $tenantConfig));

    expect(Arr::only($tenant->database()->connection(), ['read', 'write', 'direct']))->toBe($expected);
})->with([
    'read database' => [['read' => ['database' => 'main']], [], ['read' => ['database' => 'acme_db']]],
    'write database' => [['write' => ['database' => 'main']], [], ['write' => ['database' => 'acme_db']]],
    'one of multiple read configs' => [
        ['read' => [['host' => 'replica1'], ['host' => 'replica2', 'database' => 'main']]],
        [],
        ['read' => [['host' => 'replica1'], ['host' => 'replica2', 'database' => 'acme_db']]],
    ],
    'direct credentials' => [
        ['driver' => 'pgsql', 'direct' => ['host' => 'postgres', 'username' => 'root', 'password' => 'password']],
        ['tenancy_db_username' => 'acme', 'tenancy_db_password' => 'secret'],
        ['direct' => ['host' => 'postgres', 'username' => 'acme', 'password' => 'secret']],
    ],
    'read host without tenant host' => [['read' => ['host' => 'replica']], [], ['read' => ['host' => 'replica']]],
    'direct host without tenant host' => [['driver' => 'pgsql', 'direct' => ['host' => 'postgres']], [], ['direct' => ['host' => 'postgres']]],
    'read host with tenant host matching the template host' => [['read' => ['host' => 'replica']], ['tenancy_db_host' => 'mysql'], ['read' => ['host' => 'replica']]],
    'read host with tenant port matching the template port' => [['read' => ['host' => 'replica']], ['tenancy_db_port' => 3306], ['read' => ['host' => 'replica']]],
    'read host matching the template host, with tenant host' => [['read' => ['host' => 'mysql']], ['tenancy_db_host' => 'mysql2'], ['read' => ['host' => 'mysql2']]],
]);

test('read, write and direct configs with their own host or port cannot be used with a tenant host or port', function (array $template, array $tenantConfig, string $message) {
    config(['tenancy.database.template_tenant_connection' => $template]);

    $tenant = new Tenant(array_merge(['id' => 'acme'], $tenantConfig));

    expect(fn () => $tenant->database()->connection())->toThrow(InvalidArgumentException::class, $message);
})->with([
    'read host with tenant host' => [['read' => ['host' => 'replica']], ['tenancy_db_host' => 'mysql2'], "'read' config sets its own 'host' (\"replica\")"],
    'read host with tenant port' => [['read' => ['host' => 'replica']], ['tenancy_db_port' => '3307'], "'read' config sets its own 'host' (\"replica\")"],
    'read port with tenant host' => [['read' => ['port' => '3307']], ['tenancy_db_host' => 'mysql2'], "'read' config sets its own 'port' (\"3307\")"],
    'one of multiple read configs' => [
        // 'mysql' is the template's host, only 'replica' differs from it
        ['read' => [['host' => 'mysql'], ['host' => 'replica']]],
        ['tenancy_db_host' => 'mysql2'],
        "'read' config sets its own 'host' (\"replica\")",
    ],
    'direct host with tenant host' => [['driver' => 'pgsql', 'direct' => ['host' => 'postgres']], ['tenancy_db_host' => 'mysql2'], "'direct' config sets its own 'host' (\"postgres\")"],
    'template without top-level host' => [['host' => null, 'write' => ['host' => 'mysql']], ['tenancy_db_host' => 'mysql2'], "'write' config sets its own 'host' (\"mysql\")"],
]);

test('write config of the template connection does not override the tenant host when creating tenant databases', function () {
    $tenant = new Tenant(['id' => 'acme', 'tenancy_db_host' => 'mysql2']);

    // 'mysql' is the template's host, so it's replaced with the tenant host
    config(['tenancy.database.template_tenant_connection' => ['write' => ['host' => 'mysql']]]);
    expect($tenant->database()->hostConnection()['write'])->toBe(['host' => 'mysql2']);

    // 'replica' differs from the template's host
    config(['tenancy.database.template_tenant_connection' => ['write' => ['host' => 'replica']]]);
    expect(fn () => $tenant->database()->hostConnection())->toThrow(InvalidArgumentException::class, "'write' config sets its own 'host' (\"replica\")");
});

test('tenant reads run in the tenant database when the read config of the template connection sets a database', function () {
    config([
        'tenancy.bootstrappers' => [DatabaseTenancyBootstrapper::class],
        'database.connections.central.read' => ['database' => 'main'],
        // Laravel requires a write config when a read config is set
        'database.connections.central.write' => [],
    ]);

    Event::listen(TenantCreated::class, JobPipeline::make([CreateDatabase::class])->send(function (TenantCreated $event) {
        return $event->tenant;
    })->toListener());

    $tenant = Tenant::create();

    tenancy()->initialize($tenant);

    // Selects use the read connection
    expect(DB::selectOne('SELECT DATABASE() AS name')->name)->toBe($tenant->database()->getName());
});

test('tenant migrations use the direct config of the template connection', function () {
    config([
        'tenancy.bootstrappers' => [DatabaseTenancyBootstrapper::class],
        'tenancy.database.template_tenant_connection' => 'pgsql',
        'database.connections.pgsql.direct' => ['host' => config('database.connections.pgsql.host')],
    ]);

    Event::listen(TenantCreated::class, JobPipeline::make([CreateDatabase::class])->send(function (TenantCreated $event) {
        return $event->tenant;
    })->toListener());

    $tenant1 = Tenant::create();
    $tenant2 = Tenant::create();

    $connections = [];
    Event::listen(QueryExecuted::class, function (QueryExecuted $event) use (&$connections) {
        $connections[] = $event->connectionName;
    });

    pest()->artisan('tenants:migrate')->assertSuccessful();

    // todo@release if we drop Laravel 12 support, remove this check (Laravel 12 doesn't have direct connections)
    if (method_exists(DB::connection(), 'hasDirectConnection')) {
        expect(array_unique($connections))->toContain('tenant::direct');
    }

    // Previously, the tenant::direct connection was kept when switching tenants,
    // so the migrations of the tenant migrated second ran in the database of the tenant migrated first
    $tenant1->run(fn () => expect(Schema::hasTable('users'))->toBeTrue());
    $tenant2->run(fn () => expect(Schema::hasTable('users'))->toBeTrue());
});

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
