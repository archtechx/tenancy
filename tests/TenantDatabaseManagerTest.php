<?php

declare(strict_types=1);

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Stancl\JobPipeline\JobPipeline;
use Stancl\Tenancy\Bootstrappers\DatabaseTenancyBootstrapper;
use Stancl\Tenancy\Database\DatabaseManager;
use Stancl\Tenancy\Events\TenancyEnded;
use Stancl\Tenancy\Events\TenancyInitialized;
use Stancl\Tenancy\Events\TenantCreated;
use Stancl\Tenancy\Exceptions\TenantDatabaseAlreadyExistsException;
use Stancl\Tenancy\Jobs\CreateDatabase;
use Stancl\Tenancy\Listeners\BootstrapTenancy;
use Stancl\Tenancy\Listeners\RevertToCentralContext;
use Stancl\Tenancy\TenantDatabaseManagers\MicrosoftSQLDatabaseManager;
use Stancl\Tenancy\TenantDatabaseManagers\MySQLDatabaseManager;
use Stancl\Tenancy\TenantDatabaseManagers\PermissionControlledMySQLDatabaseManager;
use Stancl\Tenancy\TenantDatabaseManagers\PostgreSQLDatabaseManager;
use Stancl\Tenancy\TenantDatabaseManagers\PostgreSQLSchemaManager;
use Stancl\Tenancy\TenantDatabaseManagers\SQLiteDatabaseManager;
use Stancl\Tenancy\Tests\Etc\Tenant;

uses(Stancl\Tenancy\Tests\TestCase::class);

test('databases can be created and deleted', function ($driver, $databaseManager) {
    Event::listen(TenantCreated::class, JobPipeline::make([CreateDatabase::class])->send(function (TenantCreated $event) {
        return $event->tenant;
    })->toListener());

    config()->set([
        "tenancy.database.managers.$driver" => $databaseManager,
    ]);

    $name = 'db' . $this->randomString();

    $manager = app($databaseManager);
    $manager->setConnection($driver);

    $this->assertFalse($manager->databaseExists($name));

    $tenant = Tenant::create([
        'tenancy_db_name' => $name,
        'tenancy_db_connection' => $driver,
    ]);

    $this->assertTrue($manager->databaseExists($name));
    $manager->deleteDatabase($tenant);
    $this->assertFalse($manager->databaseExists($name));
})->with('database_manager_provider');

test('dbs can be created when another driver is used for the central db', function () {
    $this->assertSame('central', config('database.default'));

    Event::listen(TenantCreated::class, JobPipeline::make([CreateDatabase::class])->send(function (TenantCreated $event) {
        return $event->tenant;
    })->toListener());

    $database = 'db' . $this->randomString();

    $mysqlmanager = app(MySQLDatabaseManager::class);
    $mysqlmanager->setConnection('mysql');

    $this->assertFalse($mysqlmanager->databaseExists($database));
    Tenant::create([
        'tenancy_db_name' => $database,
        'tenancy_db_connection' => 'mysql',
    ]);

    $this->assertTrue($mysqlmanager->databaseExists($database));

    $postgresManager = app(PostgreSQLDatabaseManager::class);
    $postgresManager->setConnection('pgsql');

    $database = 'db' . $this->randomString();
    $this->assertFalse($postgresManager->databaseExists($database));

    Tenant::create([
        'tenancy_db_name' => $database,
        'tenancy_db_connection' => 'pgsql',
    ]);

    $this->assertTrue($postgresManager->databaseExists($database));
});

test('the tenant connection is fully removed', function () {
    config([
        'tenancy.boostrappers' => [
            DatabaseTenancyBootstrapper::class,
        ],
    ]);

    Event::listen(TenantCreated::class, JobPipeline::make([CreateDatabase::class])->send(function (TenantCreated $event) {
        return $event->tenant;
    })->toListener());

    Event::listen(TenancyInitialized::class, BootstrapTenancy::class);
    Event::listen(TenancyEnded::class, RevertToCentralContext::class);

    $tenant = Tenant::create();

    $this->assertSame(['central'], array_keys(app('db')->getConnections()));
    $this->assertArrayNotHasKey('tenant', config('database.connections'));

    tenancy()->initialize($tenant);

    createUsersTable();

    $this->assertSame(['central', 'tenant'], array_keys(app('db')->getConnections()));
    $this->assertArrayHasKey('tenant', config('database.connections'));

    tenancy()->end();

    $this->assertSame(['central'], array_keys(app('db')->getConnections()));
    $this->assertNull(config('database.connections.tenant'));
});

test('db name is prefixed with db path when sqlite is used', function () {
    if (file_exists(database_path('foodb'))) {
        unlink(database_path('foodb')); // cleanup
    }
    config([
        'database.connections.fooconn.driver' => 'sqlite',
    ]);

    $tenant = Tenant::create([
        'tenancy_db_name' => 'foodb',
        'tenancy_db_connection' => 'fooconn',
    ]);
    app(DatabaseManager::class)->createTenantConnection($tenant);

    $this->assertSame(config('database.connections.tenant.database'), database_path('foodb'));
});

test('schema manager uses schema to separate tenant dbs', function () {
    config([
        'tenancy.database.managers.pgsql' => \Stancl\Tenancy\TenantDatabaseManagers\PostgreSQLSchemaManager::class,
        'tenancy.boostrappers' => [
            DatabaseTenancyBootstrapper::class,
        ],
    ]);

    Event::listen(TenantCreated::class, JobPipeline::make([CreateDatabase::class])->send(function (TenantCreated $event) {
        return $event->tenant;
    })->toListener());

    Event::listen(TenancyInitialized::class, BootstrapTenancy::class);

    $originalDatabaseName = config(['database.connections.pgsql.database']);

    $tenant = Tenant::create([
        'tenancy_db_connection' => 'pgsql',
    ]);
    tenancy()->initialize($tenant);

    $schemaConfig = version_compare(app()->version(), '9.0', '>=') ? 
        config('database.connections.' . config('database.default') . '.search_path') :
        config('database.connections.' . config('database.default') . '.schema');

    $this->assertSame($tenant->database()->getName(), $schemaConfig);
    $this->assertSame($originalDatabaseName, config(['database.connections.pgsql.database']));
});

test('a tenants database cannot be created when the database already exists', function () {
    Event::listen(TenantCreated::class, JobPipeline::make([CreateDatabase::class])->send(function (TenantCreated $event) {
        return $event->tenant;
    })->toListener());

    $name = 'foo' . Str::random(8);
    $tenant = Tenant::create([
        'tenancy_db_name' => $name,
    ]);

    $manager = $tenant->database()->manager();
    $this->assertTrue($manager->databaseExists($tenant->database()->getName()));

    $this->expectException(TenantDatabaseAlreadyExistsException::class);
    $tenant2 = Tenant::create([
        'tenancy_db_name' => $name,
    ]);
});

test('tenant database can be created on a foreign server', function () {
    config([
        'tenancy.database.managers.mysql' => PermissionControlledMySQLDatabaseManager::class,
        'database.connections.mysql2' => [
            'driver' => 'mysql',
            'host' => 'mysql2', // important line
            'port' => 3306,
            'database' => 'main',
            'username' => 'root',
            'password' => 'password',
            'unix_socket' => env('DB_SOCKET', ''),
            'charset' => 'utf8mb4',
            'collation' => 'utf8mb4_unicode_ci',
            'prefix' => '',
            'prefix_indexes' => true,
            'strict' => true,
            'engine' => null,
            'options' => extension_loaded('pdo_mysql') ? array_filter([
                PDO::MYSQL_ATTR_SSL_CA => env('MYSQL_ATTR_SSL_CA'),
            ]) : [],
        ],
    ]);

    Event::listen(TenantCreated::class, JobPipeline::make([CreateDatabase::class])->send(function (TenantCreated $event) {
        return $event->tenant;
    })->toListener());

    $name = 'foo' . Str::random(8);
    $tenant = Tenant::create([
        'tenancy_db_name' => $name,
        'tenancy_db_connection' => 'mysql2',
    ]);

    /** @var PermissionControlledMySQLDatabaseManager $manager */
    $manager = $tenant->database()->manager();

    $manager->setConnection('mysql');
    $this->assertFalse($manager->databaseExists($name));

    $manager->setConnection('mysql2');
    $this->assertTrue($manager->databaseExists($name));
});

test('path used by sqlite manager can be customized', function () {
    $this->markTestIncomplete();
});

// Datasets
dataset('database_manager_provider', [
    ['mysql', MySQLDatabaseManager::class],
    ['mysql', PermissionControlledMySQLDatabaseManager::class],
    ['sqlite', SQLiteDatabaseManager::class],
    ['pgsql', PostgreSQLDatabaseManager::class],
    ['pgsql', PostgreSQLSchemaManager::class],
    ['sqlsrv', MicrosoftSQLDatabaseManager::class]
]);

// Helpers
function createUsersTable()
{
    Schema::create('users', function (Blueprint $table) {
        $table->increments('id');
        $table->string('name');
        $table->string('email')->unique();
        $table->string('password');
        $table->rememberToken();
        $table->timestamps();
    });
}
