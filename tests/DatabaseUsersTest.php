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
use Stancl\Tenancy\Contracts\ManagesDatabaseUsers;
use Stancl\Tenancy\Bootstrappers\DatabaseTenancyBootstrapper;
use Stancl\Tenancy\Database\TenantDatabaseManagers\MySQLDatabaseManager;
use Stancl\Tenancy\Database\TenantDatabaseManagers\MicrosoftSQLDatabaseManager;
use Stancl\Tenancy\Database\Exceptions\TenantDatabaseUserAlreadyExistsException;
use Stancl\Tenancy\Database\TenantDatabaseManagers\PermissionControlledMySQLDatabaseManager;
use Stancl\Tenancy\Database\TenantDatabaseManagers\PermissionControlledMicrosoftSQLServerDatabaseManager;

beforeEach(function () {
    config([
        'tenancy.database.managers.mysql' => PermissionControlledMySQLDatabaseManager::class,
        'tenancy.database.managers.sqlsrv' => PermissionControlledMicrosoftSQLServerDatabaseManager::class,
        'tenancy.database.suffix' => '',
        'tenancy.database.template_tenant_connection' => 'mysql',
    ]);

    // Reset static property
    PermissionControlledMySQLDatabaseManager::$grants = [
        'ALTER', 'ALTER ROUTINE', 'CREATE', 'CREATE ROUTINE', 'CREATE TEMPORARY TABLES', 'CREATE VIEW',
        'DELETE', 'DROP', 'EVENT', 'EXECUTE', 'INDEX', 'INSERT', 'LOCK TABLES', 'REFERENCES', 'SELECT',
        'SHOW VIEW', 'TRIGGER', 'UPDATE',
    ];

    Event::listen(TenantCreated::class, JobPipeline::make([CreateDatabase::class])->send(function (TenantCreated $event) {
        return $event->tenant;
    })->toListener());
});

test('users are created when permission controlled manager is used', function (string $connection) {
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
    'mysql',
    'sqlsrv',
]);

test('a tenants database cannot be created when the user already exists', function (string $connection) {
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
    'mysql',
    'sqlsrv',
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

test('having existing databases without users and switching to permission controlled mysql manager doesnt break existing dbs', function () {
    config([
        'tenancy.database.managers.mysql' => MySQLDatabaseManager::class,
        'tenancy.database.template_tenant_connection' => 'mysql',
        'tenancy.bootstrappers' => [
            DatabaseTenancyBootstrapper::class,
        ],
    ]);

    Event::listen(TenancyInitialized::class, BootstrapTenancy::class);

    $tenant = Tenant::create([
        'id' => 'foo' . Str::random(10),
    ]);

    expect($tenant->database()->manager() instanceof MySQLDatabaseManager)->toBeTrue();

    tenancy()->initialize($tenant); // check if everything works
    tenancy()->end();

    config(['tenancy.database.managers.mysql' => PermissionControlledMySQLDatabaseManager::class]);

    tenancy()->initialize($tenant); // check if everything works

    expect($tenant->database()->manager() instanceof PermissionControlledMySQLDatabaseManager)->toBeTrue();
    expect(config('database.connections.tenant.username'))->toBe('root');
});

test('having existing databases without users and switching to permission controlled sqlsrv manager doesnt break existing dbs', function () {
    config([
        'database.default' => 'sqlsrv',
        'tenancy.database.managers.sqlsrv' => MicrosoftSQLDatabaseManager::class,
        'tenancy.database.template_tenant_connection' => 'sqlsrv',
        'tenancy.bootstrappers' => [
            DatabaseTenancyBootstrapper::class,
        ],
    ]);

    Event::listen(TenancyInitialized::class, BootstrapTenancy::class);

    $tenant = Tenant::create([
        'id' => 'foo' . Str::random(10),
    ]);

    expect($tenant->database()->manager() instanceof MicrosoftSQLDatabaseManager)->toBeTrue();

    tenancy()->initialize($tenant); // check if everything works
    tenancy()->end();

    config(['tenancy.database.managers.sqlsrv' => PermissionControlledMicrosoftSQLServerDatabaseManager::class]);

    tenancy()->initialize($tenant); // check if everything works

    expect($tenant->database()->manager() instanceof PermissionControlledMicrosoftSQLServerDatabaseManager)->toBeTrue();
    expect(config('database.connections.tenant.username'))->toBe('sa'); // default user for the sqlsrv connection
});
