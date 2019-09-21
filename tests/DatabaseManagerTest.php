<?php

declare(strict_types=1);

namespace Stancl\Tenancy\Tests;

use Stancl\Tenancy\DatabaseManager;

class DatabaseManagerTest extends TestCase
{
    public $autoInitTenancy = false;

    /** @test */
    public function reconnect_method_works()
    {
        $old_connection_name = app(\Illuminate\Database\DatabaseManager::class)->connection()->getName();
        tenancy()->init('test.localhost');
        app(\Stancl\Tenancy\DatabaseManager::class)->reconnect();
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

    /** @test */
    public function the_default_db_is_used_when_based_on_is_null()
    {
        $this->assertSame('sqlite', config('database.default'));
        config([
            'database.connections.sqlite.foo' => 'bar',
            'tenancy.database.based_on' => null,
        ]);

        tenancy()->init('test.localhost');

        $this->assertSame('tenant', config('database.default'));
        $this->assertSame('bar', config('database.connections.' . config('database.default') . '.foo'));
    }
}
