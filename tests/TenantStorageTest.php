<?php

namespace Stancl\Tenancy\Tests;

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
}
