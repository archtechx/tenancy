<?php

namespace Stancl\Tenancy\Tests;

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
}
