<?php

declare(strict_types=1);

namespace Stancl\Tenancy\Tests;

use Illuminate\Support\Facades\Event;
use Stancl\Tenancy\Tests\Etc\Tenant;
use Stancl\Tenancy\DatabaseManager;
use Stancl\Tenancy\Listeners\BootstrapTenancy;
use Stancl\JobPipeline\JobPipeline;
use Stancl\Tenancy\Events\TenancyInitialized;
use Stancl\Tenancy\Events\TenantCreated;
use Stancl\Tenancy\Exceptions\TenantDatabaseAlreadyExistsException;
use Stancl\Tenancy\Jobs\CreateDatabase;
use Stancl\Tenancy\Bootstrappers\DatabaseTenancyBootstrapper;
use Stancl\Tenancy\TenantDatabaseManagers\MySQLDatabaseManager;
use Stancl\Tenancy\TenantDatabaseManagers\PermissionControlledMySQLDatabaseManager;
use Stancl\Tenancy\TenantDatabaseManagers\PostgreSQLDatabaseManager;
use Stancl\Tenancy\TenantDatabaseManagers\PostgreSQLSchemaManager;
use Stancl\Tenancy\TenantDatabaseManagers\SQLiteDatabaseManager;
use Stancl\Tenancy\Tests\TestCase;
use Illuminate\Support\Str;

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
            "tenancy.database_managers.$driver" => $databaseManager,
            'tenancy.internal_prefix' => 'tenancy_',
        ]);

        $name = 'db' . $this->randomString();

        $this->assertFalse(app($databaseManager)->databaseExists($name));

        $tenant = Tenant::create([
            'tenancy_db_name' => $name,
            'tenancy_db_connection' => $driver,
        ]);

        $this->assertTrue(app($databaseManager)->databaseExists($name));
        app($databaseManager)->deleteDatabase($tenant);
        $this->assertFalse(app($databaseManager)->databaseExists($name));
    }

    /** @test */
    public function dbs_can_be_created_when_another_driver_is_used_for_the_central_db()
    {
        $this->assertSame('central', config('database.default'));

        Event::listen(TenantCreated::class, JobPipeline::make([CreateDatabase::class])->send(function (TenantCreated $event) {
            return $event->tenant;
        })->toListener());

        config(['tenancy.internal_prefix' => 'tenancy_']);

        $database = 'db' . $this->randomString();

        $this->assertFalse(app(MySQLDatabaseManager::class)->databaseExists($database));
        Tenant::create([
            'tenancy_db_name' => $database,
            'tenancy_db_connection' => 'mysql',
        ]);

        $this->assertTrue(app(MySQLDatabaseManager::class)->databaseExists($database));

        $database = 'db' . $this->randomString();
        $this->assertFalse(app(PostgreSQLDatabaseManager::class)->databaseExists($database));

        Tenant::create([
            'tenancy_db_name' => $database,
            'tenancy_db_connection' => 'pgsql',
        ]);

        $this->assertTrue(app(PostgreSQLDatabaseManager::class)->databaseExists($database));
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
            'tenancy.internal_prefix' => 'tenancy_',
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
            'tenancy.database_managers.pgsql' => \Stancl\Tenancy\TenantDatabaseManagers\PostgreSQLSchemaManager::class,
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
}
