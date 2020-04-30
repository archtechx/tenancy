<?php

declare(strict_types=1);

namespace Stancl\Tenancy\Tests;

use Stancl\Tenancy\StorageDrivers\Database\TenantRepository;
use Stancl\Tenancy\Tenant;

class TenantStorageTest extends TestCase
{
    /** @test */
    public function deleting_a_tenant_works()
    {
        $abc = Tenant::new()->withDomains(['abc.localhost'])->save();
        $exists = function () use ($abc) {
            return tenancy()->all()->contains(function ($tenant) use ($abc) {
                return $tenant->id === $abc->id;
            });
        };

        $this->assertTrue($exists());

        $abc->delete();

        $this->assertFalse($exists());
    }

    /** @test */
    public function set_is_a_working_alias_for_put()
    {
        tenant()->set('foo', 'bar');

        $this->assertSame('bar', tenant()->get('foo'));
    }

    /** @test */
    public function put_works_with_key_and_value_as_separate_args()
    {
        tenant()->put('foo', 'bar');

        $this->assertSame('bar', tenant()->get('foo'));
    }

    /** @test */
    public function put_works_with_key_and_value_as_a_single_arg()
    {
        $keys = ['foo', 'abc'];
        $vals = ['bar', 'xyz'];
        $data = array_combine($keys, $vals);

        tenant()->put($data);

        $this->assertSame($data, tenant()->get($keys));
    }

    /** @test */
    public function put_on_the_current_tenant_pushes_the_value_into_the_tenant_property_array()
    {
        tenant()->put('foo', 'bar');

        $this->assertSame('bar', tenancy()->getTenant('foo'));
    }

    /** @test */
    public function arrays_can_be_stored()
    {
        tenant()->put('foo', [1, 2]);

        $this->assertSame([1, 2], tenant()->get('foo'));
    }

    /** @test */
    public function associative_arrays_can_be_stored()
    {
        $data = ['a' => 'b', 'c' => 'd'];
        tenant()->put('foo', $data);

        $this->assertSame($data, tenant()->get('foo'));
    }

    /** @test */
    public function correct_storage_driver_is_used()
    {
        if (config('tenancy.storage_driver') == 'db') {
            $this->assertSame('DatabaseStorageDriver', class_basename(tenancy()->storage));
        } elseif (config('tenancy.storage_driver') == 'redis') {
            $this->assertSame('RedisStorageDriver', class_basename(tenancy()->storage));
        } else {
            dd(class_basename(config('tenancy.storage_driver')));
        }
    }

    /** @test */
    public function data_is_stored_with_correct_data_types()
    {
        tenant()->put('someBool', false);
        $this->assertSame('boolean', gettype(tenant()->get('someBool')));
        $this->assertSame('boolean', gettype(tenant()->get(['someBool'])['someBool']));

        tenant()->put('someInt', 5);
        $this->assertSame('integer', gettype(tenant()->get('someInt')));
        $this->assertSame('integer', gettype(tenant()->get(['someInt'])['someInt']));

        tenant()->put('someDouble', 11.40);
        $this->assertSame('double', gettype(tenant()->get('someDouble')));
        $this->assertSame('double', gettype(tenant()->get(['someDouble'])['someDouble']));

        tenant()->put('string', 'foo');
        $this->assertSame('string', gettype(tenant()->get('string')));
        $this->assertSame('string', gettype(tenant()->get(['string'])['string']));
    }

    /** @test */
    public function tenant_repository_uses_correct_connection()
    {
        config(['database.connections.foo' => config('database.connections.sqlite')]);
        config(['tenancy.storage_drivers.db.connection' => 'foo']);
        $this->assertSame('foo', app(TenantRepository::class)->database->getName());
    }

    /** @test */
    public function retrieving_data_without_cache_works()
    {
        Tenant::new()->withDomains(['foo.localhost'])->save();
        tenancy()->init('foo.localhost');

        tenant()->put('foo', 'bar');
        $this->assertSame('bar', tenant()->get('foo'));
        $this->assertSame(['foo' => 'bar'], tenant()->get(['foo']));

        tenancy()->endTenancy();
        tenancy()->init('foo.localhost');
        $this->assertSame('bar', tenant()->get('foo'));
        $this->assertSame(['foo' => 'bar'], tenant()->get(['foo']));
    }

    /** @test */
    public function custom_columns_work_with_db_storage_driver()
    {
        if (config('tenancy.storage_driver') != 'db') {
            $this->markTestSkipped();
        }

        tenancy()->endTenancy();

        $this->loadMigrationsFrom([
            '--path' => __DIR__ . '/Etc',
            '--database' => 'central',
        ]);
        config(['database.default' => 'sqlite']); // fix issue caused by loadMigrationsFrom

        config(['tenancy.storage_drivers.db.custom_columns' => [
            'foo',
        ]]);

        tenancy()->create(['foo.localhost']);
        tenancy()->init('foo.localhost');

        tenant()->put('foo', '111');
        $this->assertSame('111', tenant()->get('foo'));

        tenant()->put(['foo' => 'bar', 'abc' => 'xyz']);
        $this->assertSame(['foo' => 'bar', 'abc' => 'xyz'], tenant()->get(['foo', 'abc']));

        $this->assertSame('bar', \DB::connection('central')->table('tenants')->where('id', tenant('id'))->first()->foo);
    }

    /** @test */
    public function custom_columns_can_be_used_on_tenant_create()
    {
        if (config('tenancy.storage_driver') != 'db') {
            $this->markTestSkipped();
        }

        tenancy()->endTenancy();

        $this->loadMigrationsFrom([
            '--path' => __DIR__ . '/Etc',
            '--database' => 'central',
        ]);
        config(['database.default' => 'sqlite']); // fix issue caused by loadMigrationsFrom

        config(['tenancy.storage_drivers.db.custom_columns' => [
            'foo',
        ]]);

        tenancy()->create(['foo.localhost'], ['foo' => 'bar', 'abc' => 'xyz']);
        tenancy()->init('foo.localhost');

        $this->assertSame(['foo' => 'bar', 'abc' => 'xyz'], tenant()->get(['foo', 'abc']));
        $this->assertSame('bar', \DB::connection('central')->table('tenants')->where('id', tenant('id'))->first()->foo);
    }
}
