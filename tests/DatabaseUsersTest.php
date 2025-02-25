<?php

declare(strict_types=1);

use Illuminate\Support\Str;
use Illuminate\Support\Facades\DB;
use Stancl\JobPipeline\JobPipeline;
use Stancl\Tenancy\Tests\Etc\Tenant;
use Illuminate\Support\Facades\Event;
use Stancl\Tenancy\Jobs\CreateDatabase;
use Stancl\Tenancy\Events\TenantCreated;
use Stancl\Tenancy\Events\DatabaseCreated;
use Stancl\Tenancy\Database\DatabaseManager;
use Stancl\Tenancy\Events\TenancyInitialized;
use Stancl\Tenancy\Listeners\BootstrapTenancy;
use Stancl\Tenancy\Database\Contracts\ManagesDatabaseUsers;
use Stancl\Tenancy\Bootstrappers\DatabaseTenancyBootstrapper;
use Stancl\Tenancy\Database\TenantDatabaseManagers\MySQLDatabaseManager;
use Stancl\Tenancy\Database\TenantDatabaseManagers\PostgreSQLSchemaManager;
use Stancl\Tenancy\Database\TenantDatabaseManagers\PostgreSQLDatabaseManager;
use Stancl\Tenancy\Database\TenantDatabaseManagers\MicrosoftSQLDatabaseManager;
use Stancl\Tenancy\Database\Exceptions\TenantDatabaseUserAlreadyExistsException;
use Stancl\Tenancy\Database\TenantDatabaseManagers\PermissionControlledMySQLDatabaseManager;
use Stancl\Tenancy\Database\TenantDatabaseManagers\PermissionControlledPostgreSQLSchemaManager;
use Stancl\Tenancy\Database\TenantDatabaseManagers\PermissionControlledPostgreSQLDatabaseManager;
use Stancl\Tenancy\Database\TenantDatabaseManagers\PermissionControlledMicrosoftSQLServerDatabaseManager;
use function Stancl\Tenancy\Tests\pest;

beforeEach(function () {
    config([
        'tenancy.database.managers.mysql' => PermissionControlledMySQLDatabaseManager::class,
        'tenancy.database.managers.sqlsrv' => PermissionControlledMicrosoftSQLServerDatabaseManager::class,
        'tenancy.database.managers.pgsql' => PermissionControlledPostgreSQLDatabaseManager::class,
        'tenancy.database.suffix' => '',
        'tenancy.database.template_tenant_connection' => 'mysql',
    ]);

    // Reset static property
    PermissionControlledMySQLDatabaseManager::$grants = [
        'ALTER', 'ALTER ROUTINE', 'CREATE', 'CREATE ROUTINE', 'CREATE TEMPORARY TABLES', 'CREATE VIEW',
        'DELETE', 'DROP', 'EVENT', 'EXECUTE', 'INDEX', 'INSERT', 'LOCK TABLES', 'REFERENCES', 'SELECT',
        'SHOW VIEW', 'TRIGGER', 'UPDATE',
    ];

    PermissionControlledMicrosoftSQLServerDatabaseManager::$grants = [
        'SELECT', 'INSERT', 'UPDATE', 'DELETE', 'EXECUTE',
    ];

    Event::listen(TenantCreated::class, JobPipeline::make([CreateDatabase::class])->send(function (TenantCreated $event) {
        return $event->tenant;
    })->toListener());
});

test('users are created when permission controlled manager is used', function (string $connection, string|null $manager = null) {
    if ($manager) {
        config(["tenancy.database.managers.{$connection}" => $manager]);
    }

    config([
        'database.default' => $connection,
        'tenancy.database.template_tenant_connection' => $connection,
    ]);

    $tenant = new Tenant([
        'id' => 'foo' . Str::random(10),
    ]);

    $tenant->database()->makeCredentials();

    /** @var ManagesDatabaseUsers $manager */
    $manager = $tenant->database()->manager();
    $username = $tenant->database()->getUsername();

    expect($manager->userExists($username))->toBeFalse();

    $tenant->save();

    expect($manager->userExists($username))->toBeTrue();

    if ($connection === 'sqlsrv') {
        app(DatabaseManager::class)->connectToTenant($tenant);

        expect((bool) DB::select("SELECT dp.name as username FROM sys.database_principals dp WHERE dp.name = '{$username}'"))->toBeTrue();
    }
})->with([
    ['mysql'],
    ['sqlsrv'],
    ['pgsql', PermissionControlledPostgreSQLDatabaseManager::class],
    ['pgsql', PermissionControlledPostgreSQLSchemaManager::class],
]);

test('a tenants database cannot be created when the user already exists', function (string $connection, string|null $manager = null) {
    if ($manager) {
        config(["tenancy.database.managers.{$connection}" => $manager]);
    }

    config([
        'database.default' => $connection,
        'tenancy.database.template_tenant_connection' => $connection,
    ]);

    $username = 'foo' . Str::random(8);
    $tenant = Tenant::create([
        'tenancy_db_username' => $username,
    ]);

    /** @var ManagesDatabaseUsers $manager */
    $manager = $tenant->database()->manager();
    expect($manager->userExists($tenant->database()->getUsername()))->toBeTrue();
    expect($manager->databaseExists($tenant->database()->getName()))->toBeTrue();

    pest()->expectException(TenantDatabaseUserAlreadyExistsException::class);
    Event::fake([DatabaseCreated::class]);

    $tenant2 = Tenant::create([
        'tenancy_db_username' => $username,
    ]);

    /** @var ManagesDatabaseUsers $manager */
    $manager2 = $tenant2->database()->manager();

    // database was not created because of DB transaction
    expect($manager2->databaseExists($tenant2->database()->getName()))->toBeFalse();
    Event::assertNotDispatched(DatabaseCreated::class);
})->with([
    ['mysql'],
    ['sqlsrv'],
    ['pgsql', PermissionControlledPostgreSQLDatabaseManager::class],
    ['pgsql', PermissionControlledPostgreSQLSchemaManager::class],
]);

test('correct grants are given to users using mysql', function () {
    PermissionControlledMySQLDatabaseManager::$grants = [
        'ALTER', 'ALTER ROUTINE', 'CREATE',
    ];

    $tenant = Tenant::create([
        'tenancy_db_username' => $user = 'user' . Str::random(8),
    ]);

    $query = DB::connection('mysql')->select("SHOW GRANTS FOR `{$tenant->database()->getUsername()}`@`%`")[1];
    expect($query->{"Grants for {$user}@%"})->toStartWith('GRANT CREATE, ALTER, ALTER ROUTINE ON'); // @mysql because that's the hostname within the docker network
});

test('permissions for new tables are granted to users using pgsql', function (string $manager) {
    config([
        'database.default' => 'pgsql',
        'tenancy.database.template_tenant_connection' => 'pgsql',
        'tenancy.database.managers.pgsql' => $manager,
    ]);

    Tenant::create(['tenancy_db_username' => $username = 'user' . Str::random(8)]);

    $grantCount = fn () => count(DB::select("SELECT * FROM information_schema.table_privileges WHERE grantee = '{$username}'"));

    expect($grantCount())->toBe(0);

    Event::listen(TenancyInitialized::class, function (TenancyInitialized $event) {
        app(DatabaseManager::class)->connectToTenant($event->tenancy->tenant);
    });

    // Run tenants:migrate to create tables to confirm
    // that the user will be granted privileges for newly created tables
    pest()->artisan('tenants:migrate');

    expect($grantCount())->not()->toBe(0);
})->with([
    PermissionControlledPostgreSQLDatabaseManager::class,
    PermissionControlledPostgreSQLSchemaManager::class
]);

test('correct grants are given to users using sqlsrv', function () {
    config([
        'database.default' => 'sqlsrv',
        'tenancy.database.template_tenant_connection' => 'sqlsrv',
    ]);

    PermissionControlledMicrosoftSQLServerDatabaseManager::$grants = ['SELECT'];

    $tenant = Tenant::create(['tenancy_db_username' => $user = 'tenant_user' . Str::random(8)]);

    // Connect to the tenant database to access tenant database user's permissions
    app(DatabaseManager::class)->connectToTenant($tenant);

    $userGrants = DB::select("SELECT dp.permission_name as name FROM sys.database_permissions dp WHERE USER_NAME(dp.grantee_principal_id) = '$user'");

    expect(array_map(fn ($grant) => $grant->name, $userGrants))->toEqual(array_merge(
        ['CONNECT'], // Granted automatically
        PermissionControlledMicrosoftSQLServerDatabaseManager::$grants
    ));
});

test('having existing databases without users and switching to permission controlled manager doesnt break existing dbs', function (string $driver, string $manager, string $permissionControlledManager, string $defaultUser) {
    config([
        'database.default' => $driver,
        'tenancy.database.managers.' . $driver => $manager,
        'tenancy.database.template_tenant_connection' => $driver,
        'tenancy.bootstrappers' => [
            DatabaseTenancyBootstrapper::class,
        ],
    ]);

    Event::listen(TenancyInitialized::class, BootstrapTenancy::class);

    $tenant = Tenant::create([
        'id' => 'foo' . Str::random(10),
    ]);

    expect($tenant->database()->manager() instanceof $manager)->toBeTrue();

    tenancy()->initialize($tenant); // check if everything works
    tenancy()->end();

    config(['tenancy.database.managers.' . $driver => $permissionControlledManager]);

    tenancy()->initialize($tenant); // check if everything works

    expect($tenant->database()->manager() instanceof $permissionControlledManager)->toBeTrue();
    expect(config('database.connections.tenant.username'))->toBe($defaultUser);
})->with([
    ['mysql', MySQLDatabaseManager::class, PermissionControlledMySQLDatabaseManager::class, 'root'],
    ['pgsql', PostgreSQLDatabaseManager::class, PermissionControlledPostgreSQLDatabaseManager::class, 'root'],
    ['pgsql', PostgreSQLSchemaManager::class, PermissionControlledPostgreSQLSchemaManager::class, 'root'],
    ['sqlsrv', MicrosoftSQLDatabaseManager::class, PermissionControlledMicrosoftSQLServerDatabaseManager::class, 'sa'],
]);
