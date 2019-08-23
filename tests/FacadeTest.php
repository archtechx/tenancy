<?php

declare(strict_types=1);

namespace Stancl\Tenancy\Tests;

use Tenant;
use Tenancy;

class FacadeTest extends TestCase
{
    /** @test */
    public function tenant_manager_can_be_accessed_using_the_Tenancy_facade()
    {
        tenancy()->put('foo', 'bar');
        Tenancy::put('abc', 'xyz');

        $this->assertSame('bar', Tenancy::get('foo'));
        $this->assertSame('xyz', Tenancy::get('abc'));
    }

    /** @test */
    public function tenant_manager_can_be_accessed_using_the_Tenant_facade()
    {
        tenancy()->put('foo', 'bar');
        Tenant::put('abc', 'xyz');

        $this->assertSame('bar', Tenant::get('foo'));
        $this->assertSame('xyz', Tenant::get('abc'));
    }
}
