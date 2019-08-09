<?php

namespace Stancl\Tenancy\Tests;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Artisan;
use Stancl\Tenancy\Tests\Etc\ExampleSeeder;

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
        $this->assertFalse(Schema::hasTable('users'));

        $old_connection_name = app(\Illuminate\Database\DatabaseManager::class)->connection()->getName();
        Artisan::call('tenants:migrate');
        $new_connection_name = app(\Illuminate\Database\DatabaseManager::class)->connection()->getName();

        $this->assertFalse(Schema::hasTable('users'));
        $this->assertEquals($old_connection_name, $new_connection_name);
        $this->assertNotEquals('tenant', $new_connection_name);
    }

    /** @test */
    public function migrate_command_works_without_options()
    {
        $this->assertFalse(Schema::hasTable('users'));
        Artisan::call('tenants:migrate');
        $this->assertFalse(Schema::hasTable('users'));
        tenancy()->init('localhost');
        $this->assertTrue(Schema::hasTable('users'));
    }

    /** @test */
    public function migrate_command_works_with_tenants_option()
    {
        $tenant = tenant()->create('test.localhost');
        Artisan::call('tenants:migrate', [
            '--tenants' => [$tenant['uuid']],
        ]);

        $this->assertFalse(Schema::hasTable('users'));
        tenancy()->init('localhost');
        $this->assertFalse(Schema::hasTable('users'));

        tenancy()->init('test.localhost');
        $this->assertTrue(Schema::hasTable('users'));
    }

    /** @test */
    public function rollback_command_works()
    {
        Artisan::call('tenants:migrate');
        $this->assertFalse(Schema::hasTable('users'));
        tenancy()->init('localhost');
        $this->assertTrue(Schema::hasTable('users'));
        Artisan::call('tenants:rollback');
        $this->assertFalse(Schema::hasTable('users'));
    }

    /** @test */
    public function seed_command_works()
    {
        $this->markTestIncomplete();
    }

    /** @test */
    public function database_connection_is_switched_to_default()
    {
        $originalDBName = DB::connection()->getDatabaseName();

        Artisan::call('tenants:migrate');
        $this->assertSame($originalDBName, DB::connection()->getDatabaseName());

        Artisan::call('tenants:seed', ['--class' => ExampleSeeder::class]);
        $this->assertSame($originalDBName, DB::connection()->getDatabaseName());

        Artisan::call('tenants:rollback');
        $this->assertSame($originalDBName, DB::connection()->getDatabaseName());

        $this->run_commands_works();
        $this->assertSame($originalDBName, DB::connection()->getDatabaseName());
    }

    /** @test */
    public function database_connection_is_switched_to_default_when_tenancy_has_been_initialized()
    {
        tenancy()->init('localhost');

        $this->database_connection_is_switched_to_default();
    }

    /** @test */
    public function run_commands_works()
    {
        $uuid = tenant()->create('run.localhost')['uuid'];

        Artisan::call('tenants:migrate', ['--tenants' => $uuid]);
<<<<<<< HEAD
        
        $this->artisan("tenants:run foo --tenants=$uuid --argument='a=foo' --option='b=bar' --option='c=xyz'")
=======

        $this->artisan("tenants:run foo --tenants=$uuid a b")
>>>>>>> 1322d02c786f86c50878b63af3a37ec6079238d3
            ->expectsOutput("User's name is Test command")
            ->expectsOutput('foo')
            ->expectsOutput('xyz');
    }
}
