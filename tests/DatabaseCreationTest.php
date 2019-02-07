<?php

namespace Stancl\Tenancy\Tests;

use Illuminate\Support\Facades\DB;
use Stancl\Tenancy\DatabaseManager;
use Illuminate\Support\Facades\Queue;
use Stancl\Tenancy\Jobs\QueuedDatabaseCreator;

class DatabaseCreationTest extends TestCase
{
    /** @test */
    public function sqlite_database_is_created()
    {
        $db_name = 'testdatabase' . $this->randomString(10) . '.sqlite';
        app(DatabaseManager::class)->create($db_name, 'sqlite');
        $this->assertFileExists(database_path($db_name));
    }

    /** @test */
    public function mysql_database_is_created()
    {
        if (! $this->isTravis()) {
            $this->markTestSkipped('As to not bloat your MySQL instance with test databases, this test is not run by default.');
        }

        config()->set([
            'database.default' => 'mysql',
            'database.connections.mysql.database' => 'travis_tenancy',
        ]);

        $db_name = 'testdatabase' . $this->randomString(10);
        app(DatabaseManager::class)->create($db_name, 'mysql');
        $this->assertNotEmpty(DB::select("SELECT SCHEMA_NAME FROM INFORMATION_SCHEMA.SCHEMATA WHERE SCHEMA_NAME = '$db_name'"));
    }

    /** @test */
    public function database_creation_can_be_queued()
    {
        Queue::fake();

        config()->set('tenancy.queue_database_creation', true);
        $db_name = 'testdatabase' . $this->randomString(10) . '.sqlite';
        app(DatabaseManager::class)->create($db_name, 'sqlite');

        Queue::assertPushed(QueuedDatabaseCreator::class);
    }
}
