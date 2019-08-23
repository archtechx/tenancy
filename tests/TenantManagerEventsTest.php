<?php

declare(strict_types=1);

namespace Stancl\Tenancy\Tests;

use Tenant;
use Tenancy;

class TenantManagerEventsTest extends TestCase
{
    /** @test */
    public function bootstrapping_event_works()
    {
        $uuid = tenant()->create('foo.localhost')['uuid'];

        Tenancy::bootstrapping(function ($tenantManager) use ($uuid) {
            if ($tenantManager->tenant['uuid'] === $uuid) {
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
        $uuid = tenant()->create('foo.localhost')['uuid'];

        Tenancy::bootstrapped(function ($tenantManager) use ($uuid) {
            if ($tenantManager->tenant['uuid'] === $uuid) {
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
        $uuid = tenant()->create('foo.localhost')['uuid'];

        Tenancy::ending(function ($tenantManager) use ($uuid) {
            if ($tenantManager->tenant['uuid'] === $uuid) {
                config(['tenancy.foo' => 'bar']);
            }
        });

        $this->assertSame(null, config('tenancy.foo'));
        tenancy()->init('foo.localhost');
        $this->assertSame(null, config('tenancy.foo'));
        tenancy()->end();
        $this->assertSame('bar', config('tenancy.foo'));
    }

    /** @test */
    public function ended_event_works()
    {
        $uuid = tenant()->create('foo.localhost')['uuid'];

        Tenancy::ended(function ($tenantManager) use ($uuid) {
            if ($tenantManager->tenant['uuid'] === $uuid) {
                config(['tenancy.foo' => 'bar']);
            }
        });

        $this->assertSame(null, config('tenancy.foo'));
        tenancy()->init('foo.localhost');
        $this->assertSame(null, config('tenancy.foo'));
        tenancy()->end();
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

        $uuid = Tenant::create('abc.localhost')['uuid'];

        Tenancy::bootstrapping(function ($tenancy) use ($uuid) {
            if ($tenancy->tenant['uuid'] === $uuid) {
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

        $uuid = Tenant::create('abc.localhost')['uuid'];

        Tenancy::bootstrapping(function ($tenancy) use ($uuid) {
            if ($tenancy->tenant['uuid'] === $uuid) {
                $tenancy->database->useConnection('tenantabc');
                // return ['database'];
            }
        });

        $this->assertNotSame('tenantabc', \DB::connection()->getConfig()['name']);
        tenancy()->init('abc.localhost');
        $this->assertSame('tenant', \DB::connection()->getConfig()['name']);
    }
}
