<?php

declare(strict_types=1);

namespace Stancl\Tenancy\Tests;

use Mockery;
use Stancl\Tenancy\Contracts\StorageDriver;
use Stancl\Tenancy\Tenant;
use Tenancy;

class TenantClassTest extends TestCase
{
    public $autoInitTenancy = false;
    public $autoCreateTenant = false;

    /** @test */
    public function data_cache_works_properly()
    {
        $spy = Mockery::spy(config('tenancy.storage_driver'))->makePartial();
        $this->instance(StorageDriver::class, $spy);

        $tenant = Tenant::create(['foo.localhost'], ['foo' => 'bar']);
        $this->assertSame('bar', $tenant->data['foo']);

        $tenant->put('abc', 'xyz');
        $this->assertSame('xyz', $tenant->data['abc']);

        $tenant->put(['aaa' => 'bbb', 'ccc' => 'ddd']);
        $this->assertSame('bbb', $tenant->data['aaa']);
        $this->assertSame('ddd', $tenant->data['ccc']);

        $spy->shouldNotHaveReceived('get');

        $this->assertSame(null, $tenant->dfuighdfuigfhdui);
        $spy->shouldHaveReceived('get')->once();

        Mockery::close();
    }

    /** @test */
    public function tenant_can_have_multiple_domains()
    {
        $tenant = Tenant::create(['foo.localhost', 'bar.localhost']);
        $this->assertSame(['foo.localhost', 'bar.localhost'], $tenant->domains);
        $this->assertSame($tenant->id, Tenancy::findByDomain('foo.localhost')->id);
        $this->assertSame($tenant->id, Tenancy::findByDomain('bar.localhost')->id);
    }
}
