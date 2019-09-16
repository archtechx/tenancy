<?php

declare(strict_types=1);

namespace Stancl\Tenancy\Tests;

use Stancl\Tenancy\Tenant;
use Tenancy;

class TenantManagerEventsTest extends TestCase
{
    /** @test */
    public function bootstrapping_event_works()
    {
        $id = Tenant::new()->withDomains(['foo.localhost'])->save()['id'];

        Tenancy::bootstrapping(function ($tenantManager) use ($id) {
            if ($tenantManager->tenant['id'] === $id) {
                config(['tenancy.foo' => 'bar']);
            }
        });

        $this->assertSame(null, config('tenancy.foo'));
        tenancy()->init('foo.localhost');
        $this->assertSame('bar', config('tenancy.foo'));
    }

    /** @test */
    public function bootstrapped_event_works()
    {
        $id = Tenant::new()->withDomains(['foo.localhost'])->save()['id'];

        Tenancy::bootstrapped(function ($tenantManager) use ($id) {
            if ($tenantManager->tenant['id'] === $id) {
                config(['tenancy.foo' => 'bar']);
            }
        });

        $this->assertSame(null, config('tenancy.foo'));
        tenancy()->init('foo.localhost');
        $this->assertSame('bar', config('tenancy.foo'));
    }

    /** @test */
    public function ending_event_works()
    {
        $id = Tenant::new()->withDomains(['foo.localhost'])->save()['id'];

        Tenancy::ending(function ($tenantManager) use ($id) {
            if ($tenantManager->tenant['id'] === $id) {
                config(['tenancy.foo' => 'bar']);
            }
        });

        $this->assertSame(null, config('tenancy.foo'));
        tenancy()->init('foo.localhost');
        $this->assertSame(null, config('tenancy.foo'));
        tenancy()->endTenancy();
        $this->assertSame('bar', config('tenancy.foo'));
    }

    /** @test */
    public function ended_event_works()
    {
        $id = Tenant::new()->withDomains(['foo.localhost'])->save()['id'];

        Tenancy::ended(function ($tenantManager) use ($id) {
            if ($tenantManager->tenant['id'] === $id) {
                config(['tenancy.foo' => 'bar']);
            }
        });

        $this->assertSame(null, config('tenancy.foo'));
        tenancy()->init('foo.localhost');
        $this->assertSame(null, config('tenancy.foo'));
        tenancy()->endTenancy();
        $this->assertSame('bar', config('tenancy.foo'));
    }

    /** @test */
    public function event_returns_a_collection()
    {
        // Note: The event() method should not be called by your code.
        tenancy()->bootstrapping(function ($tenancy) {
            return ['database'];
        });
        tenancy()->bootstrapping(function ($tenancy) {
            return ['redis', 'cache'];
        });

        $prevents = tenancy()->event('bootstrapping');
        $this->assertEquals(collect(['database', 'redis', 'cache']), $prevents);
    }

    /** @test */
    public function database_can_be_reconnected_using_event_hooks()
    {
        config(['database.connections.tenantabc' => [
            'driver' => 'sqlite',
            'database' => database_path('some_special_database.sqlite'),
        ]]);

        $id = Tenant::create('abc.localhost')['id'];

        Tenancy::bootstrapping(function ($tenancy) use ($id) {
            if ($tenancy->tenant['id'] === $id) {
                $tenancy->database->useConnection('tenantabc');

                return ['database'];
            }
        });

        $this->assertNotSame('tenantabc', \DB::connection()->getConfig()['name']);
        tenancy()->init('abc.localhost');
        $this->assertSame('tenantabc', \DB::connection()->getConfig()['name']);
    }

    /** @test */
    public function database_cannot_be_reconnected_without_using_prevents()
    {
        config(['database.connections.tenantabc' => [
            'driver' => 'sqlite',
            'database' => database_path('some_special_database.sqlite'),
        ]]);

        $id = Tenant::create('abc.localhost')['id'];

        Tenancy::bootstrapping(function ($tenancy) use ($id) {
            if ($tenancy->tenant['id'] === $id) {
                $tenancy->database->useConnection('tenantabc');
                // return ['database'];
            }
        });

        $this->assertNotSame('tenantabc', \DB::connection()->getConfig()['name']);
        tenancy()->init('abc.localhost');
        $this->assertSame('tenant', \DB::connection()->getConfig()['name']);
    }
}
