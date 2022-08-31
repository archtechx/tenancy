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
use Stancl\Tenancy\Database\Exceptions\TenantDatabaseAlreadyExistsException;
use Stancl\Tenancy\Jobs\CreateDatabase;
use Stancl\Tenancy\Listeners\BootstrapTenancy;
use Stancl\Tenancy\Listeners\RevertToCentralContext;
use Stancl\Tenancy\Database\TenantDatabaseManagers\MicrosoftSQLDatabaseManager;
use Stancl\Tenancy\Database\TenantDatabaseManagers\MySQLDatabaseManager;
use Stancl\Tenancy\Database\TenantDatabaseManagers\PermissionControlledMySQLDatabaseManager;
use Stancl\Tenancy\Database\TenantDatabaseManagers\PostgreSQLDatabaseManager;
use Stancl\Tenancy\Database\TenantDatabaseManagers\PostgreSQLSchemaManager;
use Stancl\Tenancy\Database\TenantDatabaseManagers\SQLiteDatabaseManager;
use Stancl\Tenancy\Tests\Etc\Tenant;

test('databases can be created and deleted', function ($driver, $databaseManager) {
    Event::listen(TenantCreated::class, JobPipeline::make([CreateDatabase::class])->send(function (TenantCreated $event) {
        return $event->tenant;
    })->toListener());

    config()->set([
        "tenancy.database.managers.$driver" => $databaseManager,
    ]);

    $name = 'db' . pest()->randomString();

    $manager = app($databaseManager);
    $manager->setConnection($driver);

    expect($manager->databaseExists($name))->toBeFalse();

    $tenant = Tenant::create([
        'tenancy_db_name' => $name,
        'tenancy_db_connection' => $driver,
    ]);

    expect($manager->databaseExists($name))->toBeTrue();
    $manager->deleteDatabase($tenant);
    expect($manager->databaseExists($name))->toBeFalse();
})->with('database_manager_provider');

test('dbs can be created when another driver is used for the central db', function () {
    expect(config('database.default'))->toBe('central');

    Event::listen(TenantCreated::class, JobPipeline::make([CreateDatabase::class])->send(function (TenantCreated $event) {
        return $event->tenant;
    })->toListener());

    $database = 'db' . pest()->randomString();

    $mysqlmanager = app(MySQLDatabaseManager::class);
    $mysqlmanager->setConnection('mysql');

    expect($mysqlmanager->databaseExists($database))->toBeFalse();
    Tenant::create([
        'tenancy_db_name' => $database,
        'tenancy_db_connection' => 'mysql',
    ]);

    expect($mysqlmanager->databaseExists($database))->toBeTrue();

    $postgresManager = app(PostgreSQLDatabaseManager::class);
    $postgresManager->setConnection('pgsql');

    $database = 'db' . pest()->randomString();
    expect($postgresManager->databaseExists($database))->toBeFalse();

    Tenant::create([
        'tenancy_db_name' => $database,
        'tenancy_db_connection' => 'pgsql',
    ]);

    expect($postgresManager->databaseExists($database))->toBeTrue();
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

    expect(array_keys(app('db')->getConnections()))->toBe(['central']);
    pest()->assertArrayNotHasKey('tenant', config('database.connections'));

    tenancy()->initialize($tenant);

    createUsersTable();

    expect(array_keys(app('db')->getConnections()))->toBe(['central', 'tenant']);
    pest()->assertArrayHasKey('tenant', config('database.connections'));

    tenancy()->end();

    expect(array_keys(app('db')->getConnections()))->toBe(['central']);
    expect(config('database.connections.tenant'))->toBeNull();
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

    expect(database_path('foodb'))->toBe(config('database.connections.tenant.database'));
});

test('schema manager uses schema to separate tenant dbs', function () {
    config([
        'tenancy.database.managers.pgsql' => \Stancl\Tenancy\Database\TenantDatabaseManagers\PostgreSQLSchemaManager::class,
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

    expect($schemaConfig)->toBe($tenant->database()->getName());
    expect(config(['database.connections.pgsql.database']))->toBe($originalDatabaseName);
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
    expect($manager->databaseExists($tenant->database()->getName()))->toBeTrue();

    pest()->expectException(TenantDatabaseAlreadyExistsException::class);
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
    expect($manager->databaseExists($name))->toBeFalse();

    $manager->setConnection('mysql2');
    expect($manager->databaseExists($name))->toBeTrue();
});

test('path used by sqlite manager can be customized', function () {
    pest()->markTestIncomplete();
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
