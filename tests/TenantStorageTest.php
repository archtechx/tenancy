<?php

namespace Stancl\Tenancy\Tests;

use Stancl\Tenancy\Interfaces\StorageDriver;

class TenantStorageTest extends TestCase
{
    public function setUp()
    {
        parent::setUp();

        // todo find a way to run this for each storage driver (once there are more of them)

        $this->storage = app(StorageDriver::class);
    }

    /** @test */
    public function put_works_with_key_and_value_as_separate_args()
    {
        tenancy()->put('foo', 'bar');

        $this->assertSame('bar', $this->storage->get(tenant('uuid'), 'foo'));
    }

    /** @test */
    public function put_works_with_key_and_value_as_a_single_arg()
    {
        $keys = ['foo', 'abc'];
        $vals = ['bar', 'xyz'];
        $data = array_combine($keys, $vals);

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
        $data = array_combine($keys, $vals);

        tenancy()->put($data, null, $uuid);

        $this->assertSame($vals, tenancy()->get($keys, $uuid));
        $this->assertNotSame($vals, tenancy()->get($keys));
        $this->assertFalse(array_intersect($data, tenant()->tenant) == $data); // assert array not subset
    }
}
