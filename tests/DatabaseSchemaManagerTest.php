<?php

declare(strict_types=1);

namespace Stancl\Tenancy\Tests;

use Illuminate\Support\Str;
use Stancl\Tenancy\Tenant;

class DatabaseSchemaManagerTest extends TestCase
{
    public $autoInitTenancy = false;

    protected function getEnvironmentSetUp($app)
    {
        parent::getEnvironmentSetUp($app);

        $app['config']->set([
            'database.default' => 'pgsql',
            'database.connections.pgsql.database' => 'main',
            'database.connections.pgsql.schema' => 'public',
            'tenancy.database.based_on' => null,
            'tenancy.database.suffix' => '',
            'tenancy.database.separate_by' => 'schema',
            'tenancy.database_managers.pgsql' => \Stancl\Tenancy\TenantDatabaseManagers\PostgreSQLSchemaManager::class,
        ]);
    }

    /** @test */
    public function reconnect_method_works()
    {
        $old_connection_name = app(\Illuminate\Database\DatabaseManager::class)->connection()->getName();

        tenancy()->init('test.localhost');

        app(\Stancl\Tenancy\DatabaseManager::class)->reconnect();

        $new_connection_name = app(\Illuminate\Database\DatabaseManager::class)->connection()->getName();

        $this->assertSame($old_connection_name, $new_connection_name);
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

    /** @test */
    public function databases_are_separated_using_schema_and_not_database()
    {
        tenancy()->create('foo.localhost');
        tenancy()->init('foo.localhost');
        $this->assertSame('tenant', config('database.default'));
        $this->assertSame('main', config('database.connections.tenant.database'));

        $schema1 = config('database.connections.' . config('database.default') . '.schema');
        $database1 = config('database.connections.' . config('database.default') . '.database');

        tenancy()->create('bar.localhost');
        tenancy()->init('bar.localhost');
        $this->assertSame('tenant', config('database.default'));
        $this->assertSame('main', config('database.connections.tenant.database'));

        $schema2 = config('database.connections.' . config('database.default') . '.schema');
        $database2 = config('database.connections.' . config('database.default') . '.database');

        $this->assertSame($database1, $database2);
        $this->assertNotSame($schema1, $schema2);
    }

    /** @test */
    public function schemas_are_separated()
    {
        // copied from DataSeparationTest

        $tenant1 = Tenant::create('tenant1.localhost');
        $tenant2 = Tenant::create('tenant2.localhost');
        \Artisan::call('tenants:migrate', [
            '--tenants' => [$tenant1['id'], $tenant2['id']],
        ]);

        tenancy()->init('tenant1.localhost');
        User::create([
            'name' => 'foo',
            'email' => 'foo@bar.com',
            'email_verified_at' => now(),
            'password' => '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', // password
            'remember_token' => Str::random(10),
        ]);
        $this->assertSame('foo', User::first()->name);

        tenancy()->init('tenant2.localhost');
        $this->assertSame(null, User::first());

        User::create([
            'name' => 'xyz',
            'email' => 'xyz@bar.com',
            'email_verified_at' => now(),
            'password' => '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', // password
            'remember_token' => Str::random(10),
        ]);

        $this->assertSame('xyz', User::first()->name);
        $this->assertSame('xyz@bar.com', User::first()->email);

        tenancy()->init('tenant1.localhost');
        $this->assertSame('foo', User::first()->name);
        $this->assertSame('foo@bar.com', User::first()->email);

        $tenant3 = Tenant::create('tenant3.localhost');
        \Artisan::call('tenants:migrate', [
            '--tenants' => [$tenant1['id'], $tenant3['id']],
        ]);

        tenancy()->init('tenant3.localhost');
        $this->assertSame(null, User::first());

        tenancy()->init('tenant1.localhost');
        \DB::table('users')->where('id', 1)->update(['name' => 'xxx']);
        $this->assertSame('xxx', User::first()->name);
    }
}
