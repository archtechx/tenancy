<?php

namespace Stancl\Tenancy\Tests;

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
}
