<?php

declare(strict_types=1);

namespace Stancl\Tenancy\Tests;

use Illuminate\Support\Facades\Queue;
use Stancl\Tenancy\Jobs\QueuedTenantDatabaseCreator;
use Stancl\Tenancy\Jobs\QueuedTenantDatabaseDeleter;
use Stancl\Tenancy\Tenant;
use Stancl\Tenancy\TenantDatabaseManagers\MySQLDatabaseManager;
use Stancl\Tenancy\TenantDatabaseManagers\PostgreSQLDatabaseManager;
use Stancl\Tenancy\TenantDatabaseManagers\PostgreSQLSchemaManager;
use Stancl\Tenancy\TenantDatabaseManagers\SQLiteDatabaseManager;

class TenantDatabaseManagerTest extends TestCase
{
    public $autoInitTenancy = false;

    /**
     * @test
     * @dataProvider database_manager_provider
     */
    public function databases_can_be_created_and_deleted($driver, $databaseManager)
    {
        if (! $this->isContainerized()) {
            $this->markTestSkipped('As to not bloat your computer with test databases, this test is not run by default.');
        }

        $name = 'db' . $this->randomString();
        $this->assertFalse(app($databaseManager)->databaseExists($name));
        app($databaseManager)->createDatabase($name);
        $this->assertTrue(app($databaseManager)->databaseExists($name));
        app($databaseManager)->deleteDatabase($name);
        $this->assertFalse(app($databaseManager)->databaseExists($name));
    }

    /** @test */
    public function dbs_can_be_created_when_another_driver_is_used_for_the_central_db()
    {
        $this->assertSame('sqlite', config('database.default'));

        $database = 'db' . $this->randomString();
        app(MySQLDatabaseManager::class)->createDatabase($database);
        $this->assertTrue(app(MySQLDatabaseManager::class)->databaseExists($database));

        $database = 'db2' . $this->randomString();
        app(PostgreSQLDatabaseManager::class)->createDatabase($database);
        $this->assertTrue(app(PostgreSQLDatabaseManager::class)->databaseExists($database));
    }

    /**
     * @test
     * @dataProvider database_manager_provider
     */
    public function databases_can_be_created_and_deleted_using_queued_commands($driver, $databaseManager)
    {
        if (! $this->isContainerized()) {
            $this->markTestSkipped('As to not bloat your computer with test databases, this test is not run by default.');
        }

        config()->set('database.default', $driver);

        $name = 'db' . $this->randomString();
        $this->assertFalse(app($databaseManager)->databaseExists($name));
        $job = new QueuedTenantDatabaseCreator(app($databaseManager), $name);

        $job->handle();
        $this->assertTrue(app($databaseManager)->databaseExists($name));

        $job = new QueuedTenantDatabaseDeleter(app($databaseManager), $name);
        $job->handle();
        $this->assertFalse(app($databaseManager)->databaseExists($name));
    }

    public function database_manager_provider()
    {
        return [
            ['mysql', MySQLDatabaseManager::class],
            ['sqlite', SQLiteDatabaseManager::class],
            ['pgsql', PostgreSQLDatabaseManager::class],
            ['pgsql', PostgreSQLSchemaManager::class],
        ];
    }

    /** @test */
    public function database_creation_can_be_queued()
    {
        Queue::fake();

        config()->set([
            'tenancy.queue_database_creation' => true,
        ]);
        Tenant::create(['test2.localhost']);

        Queue::assertPushed(QueuedTenantDatabaseCreator::class);
    }

    /** @test */
    public function database_deletion_can_be_queued()
    {
        Queue::fake();

        $tenant = Tenant::create(['test2.localhost']);
        config()->set([
            'tenancy.queue_database_deletion' => true,
            'tenancy.delete_database_after_tenant_deletion' => true,
        ]);
        $tenant->delete();

        Queue::assertPushed(QueuedTenantDatabaseDeleter::class);
    }
}
