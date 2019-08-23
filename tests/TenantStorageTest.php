<?php

declare(strict_types=1);

namespace Stancl\Tenancy\Tests;

use Stancl\Tenancy\Tenant;
use Stancl\Tenancy\StorageDrivers\RedisStorageDriver;
use Stancl\Tenancy\StorageDrivers\DatabaseStorageDriver;

class TenantStorageTest extends TestCase
{
    /** @test */
    public function deleting_a_tenant_works()
    {
        $abc = tenant()->create('abc.localhost');

        $this->assertTrue(tenant()->all()->contains($abc));

        tenant()->delete($abc['uuid']);

        $this->assertFalse(tenant()->all()->contains($abc));
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
        tenancy()->put('foo', 'bar');

        $this->assertSame('bar', tenant()->get('foo'));
    }

    /** @test */
    public function put_works_with_key_and_value_as_a_single_arg()
    {
        $keys = ['foo', 'abc'];
        $vals = ['bar', 'xyz'];
        $data = \array_combine($keys, $vals);

        tenancy()->put($data);

        $this->assertSame($vals, tenant()->get($keys));
    }

    /** @test */
    public function put_on_the_current_tenant_pushes_the_value_into_the_tenant_property_array()
    {
        tenancy()->put('foo', 'bar');

        $this->assertSame('bar', tenancy()->tenant['foo']);
    }

    /** @test */
    public function put_works_on_a_tenant_different_than_the_current_one_when_two_args_are_used()
    {
        $tenant = tenant()->create('second.localhost');
        $uuid = $tenant['uuid'];

        tenancy()->put('foo', 'bar', $uuid);

        $this->assertSame('bar', tenancy()->get('foo', $uuid));
        $this->assertNotSame('bar', tenant('foo'));
    }

    /** @test */
    public function put_works_on_a_tenant_different_than_the_current_one_when_a_single_arg_is_used()
    {
        $tenant = tenant()->create('second.localhost');
        $uuid = $tenant['uuid'];

        $keys = ['foo', 'abc'];
        $vals = ['bar', 'xyz'];
        $data = \array_combine($keys, $vals);

        tenancy()->put($data, null, $uuid);

        $this->assertSame($vals, tenancy()->get($keys, $uuid));
        $this->assertNotSame($vals, tenancy()->get($keys));
        $this->assertFalse(\array_intersect($data, tenant()->tenant) == $data); // assert array not subset
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
    public function put_returns_the_value_when_two_arguments_are_used()
    {
        $this->assertSame('bar', tenant()->put('foo', 'bar'));
    }

    /** @test */
    public function put_returns_the_key_value_pairs_when_a_single_argument_is_used()
    {
        $value = ['foo' => 'bar', 'abc' => 'xyz'];

        $this->assertSame($value, tenancy()->put($value));
    }

    /** @test */
    public function correct_storage_driver_is_used()
    {
        if (config('tenancy.storage_driver') == DatabaseStorageDriver::class) {
            $this->assertSame('DatabaseStorageDriver', class_basename(tenancy()->storage));
        } elseif (config('tenancy.storage_driver') == RedisStorageDriver::class) {
            $this->assertSame('RedisStorageDriver', class_basename(tenancy()->storage));
        }
    }

    /** @test */
    public function data_is_stored_with_correct_data_types()
    {
        tenancy()->put('someBool', false);
        $this->assertSame('boolean', \gettype(tenancy()->get('someBool')));
        $this->assertSame('boolean', \gettype(tenancy()->get(['someBool'])[0]));

        tenancy()->put('someInt', 5);
        $this->assertSame('integer', \gettype(tenancy()->get('someInt')));
        $this->assertSame('integer', \gettype(tenancy()->get(['someInt'])[0]));

        tenancy()->put('someDouble', 11.40);
        $this->assertSame('double', \gettype(tenancy()->get('someDouble')));
        $this->assertSame('double', \gettype(tenancy()->get(['someDouble'])[0]));

        tenancy()->put('string', 'foo');
        $this->assertSame('string', \gettype(tenancy()->get('string')));
        $this->assertSame('string', \gettype(tenancy()->get(['string'])[0]));
    }

    /** @test */
    public function tenant_model_uses_correct_connection()
    {
        config(['tenancy.storage.db.connection' => 'foo']);
        $this->assertSame('foo', (new Tenant)->getConnectionName());
    }

    /** @test */
    public function retrieving_data_without_cache_works()
    {
        tenant()->create('foo.localhost');
        tenancy()->init('foo.localhost');

        tenancy()->put('foo', 'bar');
        $this->assertSame('bar', tenancy()->get('foo'));
        $this->assertSame(['bar'], tenancy()->get(['foo']));

        tenancy()->end();
        tenancy()->init('foo.localhost');
        $this->assertSame('bar', tenancy()->get('foo'));
        $this->assertSame(['bar'], tenancy()->get(['foo']));
    }
}
