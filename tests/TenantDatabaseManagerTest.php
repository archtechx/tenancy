<?php

declare(strict_types=1);

namespace Stancl\Tenancy\Tests;

use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use PDO;
use Stancl\JobPipeline\JobPipeline;
use Stancl\Tenancy\Bootstrappers\DatabaseTenancyBootstrapper;
use Stancl\Tenancy\Database\DatabaseManager;
use Stancl\Tenancy\Events\TenancyInitialized;
use Stancl\Tenancy\Events\TenantCreated;
use Stancl\Tenancy\Exceptions\TenantDatabaseAlreadyExistsException;
use Stancl\Tenancy\Jobs\CreateDatabase;
use Stancl\Tenancy\Listeners\BootstrapTenancy;
use Stancl\Tenancy\TenantDatabaseManagers\MySQLDatabaseManager;
use Stancl\Tenancy\TenantDatabaseManagers\PermissionControlledMySQLDatabaseManager;
use Stancl\Tenancy\TenantDatabaseManagers\PostgreSQLDatabaseManager;
use Stancl\Tenancy\TenantDatabaseManagers\PostgreSQLSchemaManager;
use Stancl\Tenancy\TenantDatabaseManagers\SQLiteDatabaseManager;
use Stancl\Tenancy\Tests\Etc\Tenant;

class TenantDatabaseManagerTest extends TestCase
{
    /**
     * @test
     * @dataProvider database_manager_provider
     */
    public function databases_can_be_created_and_deleted($driver, $databaseManager)
    {
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
    }

    /** @test */
    public function dbs_can_be_created_when_another_driver_is_used_for_the_central_db()
    {
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
    }

    public function database_manager_provider()
    {
        return [
            ['mysql', MySQLDatabaseManager::class],
            ['mysql', PermissionControlledMySQLDatabaseManager::class],
            ['sqlite', SQLiteDatabaseManager::class],
            ['pgsql', PostgreSQLDatabaseManager::class],
            ['pgsql', PostgreSQLSchemaManager::class],
        ];
    }

    /** @test */
    public function db_name_is_prefixed_with_db_path_when_sqlite_is_used()
    {
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
    }

    /** @test */
    public function schema_manager_uses_schema_to_separate_tenant_dbs()
    {
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

        $this->assertSame($tenant->database()->getName(), config('database.connections.' . config('database.default') . '.schema'));
        $this->assertSame($originalDatabaseName, config(['database.connections.pgsql.database']));
    }

    /** @test */
    public function a_tenants_database_cannot_be_created_when_the_database_already_exists()
    {
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
    }

    /** @test */
    public function tenant_database_can_be_created_on_a_foreign_server()
    {
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
    }

    /** @test */
    public function path_used_by_sqlite_manager_can_be_customized()
    {
    }
}
