<?php

declare(strict_types=1);

namespace Stancl\Tenancy\Tests;

use Stancl\Tenancy\Contracts\Future\CanFindByAnyKey;
use Stancl\Tenancy\Tenant;

class FutureTest extends TestCase
{
    public $autoCreateTenant = false;
    public $autoInitTenancy = false;

    /** @test */
    public function keys_can_be_deleted_from_tenant_storage()
    {
        $tenant = Tenant::new()->withData(['email' => 'foo@example.com', 'role' => 'admin'])->save();

        $this->assertArrayHasKey('email', $tenant->data);
        $tenant->deleteKey('email');
        $this->assertArrayNotHasKey('email', $tenant->data);
        $this->assertArrayNotHasKey('email', tenancy()->all()->first()->data);

        $tenant->put(['foo' => 'bar', 'abc' => 'xyz']);
        $this->assertArrayHasKey('foo', $tenant->data);
        $this->assertArrayHasKey('abc', $tenant->data);

        $tenant->deleteKeys(['foo', 'abc']);
        $this->assertArrayNotHasKey('foo', $tenant->data);
        $this->assertArrayNotHasKey('abc', $tenant->data);
    }

    /** @test */
    public function tenant_can_be_identified_using_an_arbitrary_string()
    {
        if (! tenancy()->storage instanceof CanFindByAnyKey) {
            $this->markTestSkipped(get_class(tenancy()->storage) . ' does not implement the CanFindByAnyKey interface.');
        }

        $tenant = Tenant::new()->withData(['email' => 'foo@example.com'])->save();

        $this->assertSame($tenant->id, tenancy()->findByEmail('foo@example.com')->id);
    }
}
