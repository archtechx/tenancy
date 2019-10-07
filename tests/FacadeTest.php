<?php

declare(strict_types=1);

namespace Stancl\Tenancy\Tests;

use Tenancy;
use Tenant;

class FacadeTest extends TestCase
{
    /** @test */
    public function tenant_manager_can_be_accessed_using_the_Tenancy_facade()
    {
        $this->assertSame(tenancy()->getTenant(), Tenancy::getTenant());
    }

    /** @test */
    public function tenant_storage_can_be_accessed_using_the_Tenant_facade()
    {
        tenant()->put('foo', 'bar');
        Tenant::put('abc', 'xyz');

        $this->assertSame('bar', Tenant::get('foo'));
        $this->assertSame('xyz', Tenant::get('abc'));
    }

    /** @test */
    public function tenant_can_be_created_using_the_Tenant_facade()
    {
        $this->assertSame('bar', Tenant::create(['foo.localhost'], ['foo' => 'bar'])->foo);
    }
}
