<?php

declare(strict_types=1);

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Stancl\JobPipeline\JobPipeline;
use Stancl\Tenancy\Bootstrappers\DatabaseTenancyBootstrapper;
use Stancl\Tenancy\Database\Contracts\StatefulTenantDatabaseManager;
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

    if ($manager instanceof StatefulTenantDatabaseManager) {
        $manager->setConnection($driver);
    }

    expect($manager->databaseExists($name))->toBeFalse();

    $tenant = Tenant::create([
        'tenancy_db_name' => $name,
        'tenancy_db_connection' => $driver,
    ]);

    expect($manager->databaseExists($name))->toBeTrue();
    $manager->deleteDatabase($tenant);
    expect($manager->databaseExists($name))->toBeFalse();
})->with('database_managers');

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

    expect(array_keys(app('db')->getConnections()))->toBe(['central', 'tenant_host_connection']);
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

    $schemaConfig = config('database.connections.' . config('database.default') . '.search_path');

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

test('tenant database can be created and deleted on a foreign server', function () {
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

    expect($manager->databaseExists($name))->toBeTrue(); // mysql2

    $manager->setConnection('mysql');
    expect($manager->databaseExists($name))->toBeFalse(); // check that the DB doesn't exist in 'mysql'

    $manager->setConnection('mysql2'); // set the connection back
    $manager->deleteDatabase($tenant);

    expect($manager->databaseExists($name))->toBeFalse();
});

test('tenant database can be created on a foreign server by using the host from tenant config', function () {
    config([
        'tenancy.database.managers.mysql' => MySQLDatabaseManager::class,
        'tenancy.database.template_tenant_connection' => 'mysql', // This will be overridden by tenancy_db_host
        'database.connections.mysql2' => [
            'driver' => 'mysql',
            'host' => 'mysql2',
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
        'tenancy_db_host' => 'mysql2',
    ]);

    /** @var MySQLDatabaseManager $manager */
    $manager = $tenant->database()->manager();

    expect($manager->databaseExists($name))->toBeTrue();
});

test('database credentials can be provided to PermissionControlledMySQLDatabaseManager by specifying a connection', function () {
    config([
        'tenancy.database.managers.mysql' => PermissionControlledMySQLDatabaseManager::class,
        'tenancy.database.template_tenant_connection' => 'mysql',
        'database.connections.mysql2' => [
            'driver' => 'mysql',
            'host' => 'mysql2',
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

    // Create a new random database user with privileges to use with mysql2 connection
    $username = 'dbuser' . Str::random(4);
    $password = Str::random('8');
    $mysql2DB = DB::connection('mysql2');
    $mysql2DB->statement("CREATE USER `{$username}`@`%` IDENTIFIED BY '{$password}';");
    $mysql2DB->statement("GRANT ALL PRIVILEGES ON *.* TO `{$username}`@`%` identified by '{$password}' WITH GRANT OPTION;");
    $mysql2DB->statement("FLUSH PRIVILEGES;");
    
    DB::purge('mysql2'); // forget the mysql2 connection so that it uses the new credentials the next time

    config(['database.connections.mysql2.username' => $username]);
    config(['database.connections.mysql2.password' => $password]);

    Event::listen(TenantCreated::class, JobPipeline::make([CreateDatabase::class])->send(function (TenantCreated $event) {
        return $event->tenant;
    })->toListener());

    $name = 'foo' . Str::random(8);
    $usernameForNewDB = 'user_for_new_db' . Str::random(4);
    $passwordForNewDB = Str::random(8);
    $tenant = Tenant::create([
        'tenancy_db_name' => $name,
        'tenancy_db_connection' => 'mysql2',
        'tenancy_db_username' => $usernameForNewDB,
        'tenancy_db_password' => $passwordForNewDB,
    ]);

    /** @var PermissionControlledMySQLDatabaseManager $manager */
    $manager = $tenant->database()->manager();

    expect($manager->database()->getConfig('username'))->toBe($username); // user created for the HOST connection
    expect($manager->userExists($usernameForNewDB))->toBeTrue();
    expect($manager->databaseExists($name))->toBeTrue();
});

test('tenant database can be created by using the username and password from tenant config', function () {
    Event::listen(TenantCreated::class, JobPipeline::make([CreateDatabase::class])->send(function (TenantCreated $event) {
        return $event->tenant;
    })->toListener());

    config([
        'tenancy.database.managers.mysql' => MySQLDatabaseManager::class,
        'tenancy.database.template_tenant_connection' => 'mysql',
    ]);

    // Create a new random database user with privileges to use with `mysql` connection
    $username = 'dbuser' . Str::random(4);
    $password = Str::random('8');
    $mysqlDB = DB::connection('mysql');
    $mysqlDB->statement("CREATE USER `{$username}`@`%` IDENTIFIED BY '{$password}';");
    $mysqlDB->statement("GRANT ALL PRIVILEGES ON *.* TO `{$username}`@`%` identified by '{$password}' WITH GRANT OPTION;");
    $mysqlDB->statement("FLUSH PRIVILEGES;");
    
    DB::purge('mysql2'); // forget the mysql2 connection so that it uses the new credentials the next time

    // Remove `mysql` credentials to make sure we will be using the credentials from the tenant config
    config(['database.connections.mysql.username' => null]);
    config(['database.connections.mysql.password' => null]);

    $name = 'foo' . Str::random(8);
    $tenant = Tenant::create([
        'tenancy_db_name' => $name,
        'tenancy_db_username' => $username,
        'tenancy_db_password' => $password,
    ]);

    /** @var MySQLDatabaseManager $manager */
    $manager = $tenant->database()->manager();

    expect($manager->database()->getConfig('username'))->toBe($username); // user created for the HOST connection
    expect($manager->databaseExists($name))->toBeTrue();
});

test('path used by sqlite manager can be customized', function () {
    Event::listen(TenantCreated::class, JobPipeline::make([CreateDatabase::class])->send(function (TenantCreated $event) {
        return $event->tenant;
    })->toListener());

    // Set custom path for SQLite file
    SQLiteDatabaseManager::$path = $customPath = database_path('custom_' . Str::random(8));

    if (! is_dir($customPath)) {
        // Create custom directory
        mkdir($customPath);
    }

    $name = Str::random(8). '.sqlite';
    Tenant::create([
        'tenancy_db_name' => $name,
        'tenancy_db_connection' => 'sqlite',
    ]);

    expect(file_exists($customPath . '/' . $name))->toBeTrue();
});

test('template tenant connection value can be connection name or connection array', function () {
    Event::listen(TenantCreated::class, JobPipeline::make([CreateDatabase::class])->send(function (TenantCreated $event) {
        return $event->tenant;
    })->toListener());

    config([
        'tenancy.database.managers.mysql' => MySQLDatabaseManager::class,
        'tenancy.database.template_tenant_connection' => 'mysql',
    ]);

    $name = 'foo' . Str::random(8);
    $tenant = Tenant::create([
        'tenancy_db_name' => $name,
    ]);

    /** @var MySQLDatabaseManager $manager */
    $manager = $tenant->database()->manager();
    expect($manager->databaseExists($name))->toBeTrue();
    expect($manager->database()->getConfig('host'))->toBe('mysql');

    config([
        'tenancy.database.template_tenant_connection' => [
            'driver' => 'mysql',
            'url' => null,
            'host' => 'mysql2',
            'port' => '3306',
            'database' => 'main',
            'username' => 'root',
            'password' => 'password',
            'unix_socket' => '',
            'charset' => 'utf8mb4',
            'collation' => 'utf8mb4_unicode_ci',
            'prefix' => '',
            'prefix_indexes' => true,
            'strict' => true,
            'engine' => null,
            'options' => [],
        ],
    ]);

    $tenant = Tenant::create([
        'tenancy_db_name' => $name,
    ]);

    /** @var MySQLDatabaseManager $manager */
    $manager = $tenant->database()->manager();
    expect($manager->databaseExists($name))->toBeTrue();
    expect($manager->database()->getConfig('host'))->toBe('mysql2');
});

test('template tenant connection value can be partial database config', function () {
    Event::listen(TenantCreated::class, JobPipeline::make([CreateDatabase::class])->send(function (TenantCreated $event) {
        return $event->tenant;
    })->toListener());

    config([
        'database.connections.central.url' => 'example.com',
        'tenancy.database.template_tenant_connection' => [
            'url' => null,
            'host' => 'mysql2',
        ],
    ]);

    $name = 'foo' . Str::random(8);
    $tenant = Tenant::create([
        'tenancy_db_name' => $name,
    ]);

    /** @var MySQLDatabaseManager $manager */
    $manager = $tenant->database()->manager();
    expect($manager->databaseExists($name))->toBeTrue();
    expect($manager->database()->getConfig('host'))->toBe('mysql2');
    expect($manager->database()->getConfig('url'))->toBeNull();
});

// Datasets
dataset('database_managers', [
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
