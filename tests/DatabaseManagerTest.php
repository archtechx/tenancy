<?php

declare(strict_types=1);

namespace Stancl\Tenancy\Tests;

use Stancl\Tenancy\DatabaseManager;
use Stancl\Tenancy\Tenant;

class DatabaseManagerTest extends TestCase
{
    /** @test */
    public function reconnect_method_works()
    {
        $old_connection_name = app(\Illuminate\Database\DatabaseManager::class)->connection()->getName();
        $this->createTenant();
        $this->initTenancy();
        app(\Stancl\Tenancy\DatabaseManager::class)->reconnect();
        $new_connection_name = app(\Illuminate\Database\DatabaseManager::class)->connection()->getName();

        $this->assertSame($old_connection_name, $new_connection_name);
        $this->assertNotEquals('tenant', $new_connection_name);
    }

    /** @test */
    public function db_name_is_prefixed_with_db_path_when_sqlite_is_used()
    {
        if (file_exists(database_path('foodb'))) {
            unlink(database_path('foodb')); // cleanup
        }
        config(['database.connections.fooconn.driver' => 'sqlite']);
        $tenant = Tenant::new()->withData([
            '_tenancy_db_name' => 'foodb',
            '_tenancy_db_connection' => 'fooconn',
        ])->save();
        app(DatabaseManager::class)->createTenantConnection($tenant);

        $this->assertSame(config('database.connections.tenant.database'), database_path('foodb'));
    }

    /** @test */
    public function the_default_db_is_used_when_template_connection_is_null()
    {
        $this->assertSame('central', config('database.default'));
        config([
            'database.connections.central.foo' => 'bar',
            'tenancy.database.template_connection' => null,
        ]);

        $this->createTenant();
        $this->initTenancy();

        $this->assertSame('tenant', config('database.default'));
        $this->assertSame('bar', config('database.connections.' . config('database.default') . '.foo'));
    }

    /** @test */
    public function ending_tenancy_doesnt_purge_the_central_connection()
    {
        $this->markTestIncomplete('Seems like this only happens on MySQL?');

        // regression test for https://github.com/stancl/tenancy/pull/189
        // config(['tenancy.migrate_after_creation' => true]);

        tenancy()->create(['foo.localhost']);
        tenancy()->init('foo.localhost');
        tenancy()->end();

        $this->assertNotEmpty(tenancy()->all());

        tenancy()->all()->each->delete();
    }
}
