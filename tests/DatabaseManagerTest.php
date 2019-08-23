<?php

declare(strict_types=1);

namespace Stancl\Tenancy\Tests;

use Stancl\Tenancy\DatabaseManager;

class DatabaseManagerTest extends TestCase
{
    public $autoInitTenancy = false;

    /** @test */
    public function disconnect_method_works()
    {
        $old_connection_name = app(\Illuminate\Database\DatabaseManager::class)->connection()->getName();
        tenancy()->init();
        tenancy()->disconnectDatabase();
        $new_connection_name = app(\Illuminate\Database\DatabaseManager::class)->connection()->getName();

        $this->assertSame($old_connection_name, $new_connection_name);
        $this->assertNotEquals('tenant', $new_connection_name);
    }

    /** @test */
    public function db_name_is_prefixed_with_db_path_when_sqlite_is_used()
    {
        // make `tenant` not sqlite so that it has to detect sqlite from fooconn
        config(['database.connections.tenant.driver' => 'mysql']);
        app(DatabaseManager::class)->createTenantConnection('foodb', 'fooconn');

        $this->assertSame(config('database.connections.fooconn.database'), database_path('foodb'));
    }
}
