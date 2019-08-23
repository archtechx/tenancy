<?php

declare(strict_types=1);

namespace Stancl\Tenancy\Tests;

use Illuminate\Support\Facades\DB;
use Stancl\Tenancy\DatabaseManager;
use Illuminate\Support\Facades\Queue;
use Stancl\Tenancy\Jobs\QueuedTenantDatabaseCreator;
use Stancl\Tenancy\Jobs\QueuedTenantDatabaseDeleter;

class TenantDatabaseManagerTest extends TestCase
{
    /** @test */
    public function sqlite_database_can_be_created_and_deleted()
    {
        $db_name = 'testdatabase' . $this->randomString(10) . '.sqlite';
        $this->assertTrue(app(DatabaseManager::class)->create($db_name, 'sqlite'));
        $this->assertFileExists(database_path($db_name));

        $this->assertTrue(app(DatabaseManager::class)->delete($db_name, 'sqlite'));
        $this->assertFileNotExists(database_path($db_name));
    }

    /** @test */
    public function sqlite_database_can_be_created_and_deleted_using_queued_commands()
    {
        $db_name = 'testdatabase' . $this->randomString(10) . '.sqlite';

        $databaseManagers = config('tenancy.database_managers');
        $job = new QueuedTenantDatabaseCreator(app($databaseManagers['sqlite']), $db_name);
        $job->handle();

        $this->assertFileExists(database_path($db_name));

        $job = new QueuedTenantDatabaseDeleter(app($databaseManagers['sqlite']), $db_name);
        $job->handle();
        $this->assertFileNotExists(database_path($db_name));
    }

    /** @test */
    public function mysql_database_can_be_created_and_deleted()
    {
        if (! $this->isContainerized()) {
            $this->markTestSkipped('As to not bloat your MySQL instance with test databases, this test is not run by default.');
        }

        config()->set('database.default', 'mysql');

        $db_name = 'testdatabase' . $this->randomString(10);
        $this->assertTrue(app(DatabaseManager::class)->create($db_name, 'mysql'));
        $this->assertNotEmpty(DB::select("SELECT SCHEMA_NAME FROM INFORMATION_SCHEMA.SCHEMATA WHERE SCHEMA_NAME = '$db_name'"));

        $this->assertTrue(app(DatabaseManager::class)->delete($db_name, 'mysql'));
        $this->assertEmpty(DB::select("SELECT SCHEMA_NAME FROM INFORMATION_SCHEMA.SCHEMATA WHERE SCHEMA_NAME = '$db_name'"));
    }

    /** @test */
    public function mysql_database_can_be_created_and_deleted_using_queued_commands()
    {
        if (! $this->isContainerized()) {
            $this->markTestSkipped('As to not bloat your MySQL instance with test databases, this test is not run by default.');
        }

        config()->set('database.default', 'mysql');

        $db_name = 'testdatabase' . $this->randomString(10);

        $databaseManagers = config('tenancy.database_managers');
        $job = new QueuedTenantDatabaseCreator(app($databaseManagers['mysql']), $db_name);
        $job->handle();

        $this->assertNotEmpty(DB::select("SELECT SCHEMA_NAME FROM INFORMATION_SCHEMA.SCHEMATA WHERE SCHEMA_NAME = '$db_name'"));

        $databaseManagers = config('tenancy.database_managers');
        $job = new QueuedTenantDatabaseDeleter(app($databaseManagers['mysql']), $db_name);
        $job->handle();

        $this->assertEmpty(DB::select("SELECT SCHEMA_NAME FROM INFORMATION_SCHEMA.SCHEMATA WHERE SCHEMA_NAME = '$db_name'"));
    }

    /** @test */
    public function pgsql_database_can_be_created_and_deleted()
    {
        if (! $this->isContainerized()) {
            $this->markTestSkipped('As to not bloat your PostgreSQL instance with test databases, this test is not run by default.');
        }

        config()->set('database.default', 'pgsql');

        $db_name = \strtolower('testdatabase' . $this->randomString(10));
        $this->assertTrue(app(DatabaseManager::class)->create($db_name, 'pgsql'));
        $this->assertNotEmpty(DB::select("SELECT datname FROM pg_database WHERE datname = '$db_name'"));

        $this->assertTrue(app(DatabaseManager::class)->delete($db_name, 'pgsql'));
        $this->assertEmpty(DB::select("SELECT datname FROM pg_database WHERE datname = '$db_name'"));
    }

    /** @test */
    public function pgsql_database_can_be_created_and_deleted_using_queued_commands()
    {
        if (! $this->isContainerized()) {
            $this->markTestSkipped('As to not bloat your PostgreSQL instance with test databases, this test is not run by default.');
        }

        config()->set('database.default', 'pgsql');

        $db_name = \strtolower('testdatabase' . $this->randomString(10));

        $databaseManagers = config('tenancy.database_managers');
        $job = new QueuedTenantDatabaseCreator(app($databaseManagers['pgsql']), $db_name);
        $job->handle();

        $this->assertNotEmpty(DB::select("SELECT datname FROM pg_database WHERE datname = '$db_name'"));

        $databaseManagers = config('tenancy.database_managers');
        $job = new QueuedTenantDatabaseDeleter(app($databaseManagers['pgsql']), $db_name);
        $job->handle();

        $this->assertEmpty(DB::select("SELECT datname FROM pg_database WHERE datname = '$db_name'"));
    }

    /** @test */
    public function database_creation_can_be_queued()
    {
        Queue::fake();

        config()->set('tenancy.queue_database_creation', true);
        $db_name = 'testdatabase' . $this->randomString(10) . '.sqlite';
        app(DatabaseManager::class)->create($db_name, 'sqlite');

        Queue::assertPushed(QueuedTenantDatabaseCreator::class);
    }

    /** @test */
    public function database_deletion_can_be_queued()
    {
        Queue::fake();

        config()->set('tenancy.queue_database_deletion', true);
        $db_name = 'testdatabase' . $this->randomString(10) . '.sqlite';
        app(DatabaseManager::class)->delete($db_name, 'sqlite');

        Queue::assertPushed(QueuedTenantDatabaseDeleter::class);
    }
}
