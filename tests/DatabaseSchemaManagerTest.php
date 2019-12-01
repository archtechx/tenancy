<?php

declare(strict_types=1);

namespace Stancl\Tenancy\Tests;

use Stancl\Tenancy\DatabaseManager;

class DatabaseSchemaManagerTest extends TestCase
{
    public $autoInitTenancy = false;

    /**
     * Define environment setup.
     *
     * @param  \Illuminate\Foundation\Application  $app
     * @return void
     */
    protected function getEnvironmentSetUp($app)
    {
        parent::getEnvironmentSetUp($app);

        $app['config']->set('database.default', 'pgsql');
        $app['config']->set('tenancy.database.based_on', null);
        $app['config']->set('tenancy.database.suffix', '');
        $app['config']->set('tenancy.using_schema_connection', true);
    }

    /** @test */
    public function reconnect_method_works()
    {
        $old_connection_name = app(\Illuminate\Database\DatabaseManager::class)->connection()->getName();
        
        tenancy()->init('test.localhost');

        app(\Stancl\Tenancy\DatabaseManager::class)->reconnect();
        
        $new_connection_name = app(\Illuminate\Database\DatabaseManager::class)->connection()->getName();

        // $this->assertSame($old_connection_name, $new_connection_name);
        $this->assertNotEquals('tenant', $new_connection_name);
    }

    /** @test */
    public function the_default_db_is_used_when_based_on_is_null()
    {
        config(['database.default' => 'pgsql']);
        
        $this->assertSame('pgsql', config('database.default'));
        config([
            'database.connections.pgsql.foo' => 'bar',
            'tenancy.database.based_on' => null,
        ]);

        tenancy()->init('test.localhost');

        $this->assertSame('tenant', config('database.default'));
        $this->assertSame('bar', config('database.connections.' . config('database.default') . '.foo'));
    }

    /** @test */
    public function make_sure_using_schema_connection()
    {
        $tenant = tenancy()->create(['schema.localhost']);
        tenancy()->init('schema.localhost');

        $this->assertSame($tenant->getDatabaseName(), config('database.connections.' . config('database.default') . '.schema'));
    }
}
