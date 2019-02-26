<?php

namespace Stancl\Tenancy\Tests;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schema;

class CommandsTest extends TestCase
{
    public $autoInitTenancy = false;

    public function setUp(): void
    {
        parent::setUp();

        config(['tenancy.migrations_directory' => database_path('../migrations')]);
    }

    /** @test */
    public function migrate_command_doesnt_change_the_db_connection()
    {
        $old_connection_name = app(\Illuminate\Database\DatabaseManager::class)->connection()->getName();
        Artisan::call('tenants:migrate');
        $new_connection_name = app(\Illuminate\Database\DatabaseManager::class)->connection()->getName();

        $this->assertEquals($old_connection_name, $new_connection_name);
        $this->assertNotEquals('tenant', $new_connection_name);
    }

    /** @test */
    public function migrate_command_works_without_options()
    {
        $this->assertFalse(Schema::hasTable('users'));
        Artisan::call('tenants:migrate');
        $this->assertFalse(Schema::hasTable('users'));
        tenancy()->init();
        $this->assertTrue(Schema::hasTable('users'));
    }

    /** @test */
    public function migrate_command_works_with_tenants_option()
    {
        $tenant = tenant()->create('test.localhost');
        Artisan::call('tenants:migrate', [
            '--tenants' => [$tenant['uuid']]
        ]);

        $this->assertFalse(Schema::hasTable('users'));
        tenancy()->init();
        $this->assertFalse(Schema::hasTable('users'));

        tenancy()->init('test.localhost');
        $this->assertTrue(Schema::hasTable('users'));
    }

    /** @test */
    public function rollback_command_works()
    {
        Artisan::call('tenants:migrate');
        $this->assertFalse(Schema::hasTable('users'));
        tenancy()->init();
        $this->assertTrue(Schema::hasTable('users'));
        Artisan::call('tenants:rollback');
        $this->assertFalse(Schema::hasTable('users'));
    }

    /** @test */
    public function seed_command_works()
    {
        $this->markTestIncomplete();
    }
}
