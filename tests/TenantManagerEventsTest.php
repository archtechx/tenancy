<?php

declare(strict_types=1);

namespace Stancl\Tenancy\Tests;

use Stancl\Tenancy\Tenant;
use Tenancy;

class TenantManagerEventsTest extends TestCase
{
    public $autoInitTenancy = false;

    /** @test */
    public function bootstrapping_event_works()
    {
        $id = Tenant::new()->withDomains(['foo.localhost'])->save()['id'];

        Tenancy::eventListener('bootstrapping', function ($tenantManager) use ($id) {
            if ($tenantManager->getTenant('id') === $id) {
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

        Tenancy::eventListener('bootstrapped', function ($tenantManager) use ($id) {
            if ($tenantManager->getTenant('id') === $id) {
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

        Tenancy::eventListener('ending', function ($tenantManager) use ($id) {
            if ($tenantManager->getTenant('id') === $id) {
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
        Tenant::new()->withDomains(['foo.localhost'])->save()['id'];

        Tenancy::eventListener('ended', function ($tenantManager) {
            config(['tenancy.foo' => 'bar']);
        });

        $this->assertSame(null, config('tenancy.foo'));
        tenancy()->init('foo.localhost');
        $this->assertSame(null, config('tenancy.foo'));
        tenancy()->endTenancy();
        $this->assertSame('bar', config('tenancy.foo'));
    }

    /** @test */
    public function database_can_be_reconnected_using_event_hooks()
    {
        config(['database.connections.tenantabc' => [
            'driver' => 'sqlite',
            'database' => database_path('some_special_database.sqlite'),
        ]]);

        $id = Tenant::create('abc.localhost')['id'];

        Tenancy::eventListener('bootstrapping', function ($tenancy) use ($id) {
            if ($tenancy->getTenant()['id'] === $id) {
                $tenancy->database->switchConnection('tenantabc');

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

        Tenancy::eventListener('bootstrapping', function ($tenancy) use ($id) {
            if ($tenancy->getTenant()['id'] === $id) {
                $tenancy->database->switchConnection('tenantabc');
                // return ['database'];
            }
        });

        $this->assertNotSame('tenantabc', \DB::connection()->getConfig()['name']);
        tenancy()->init('abc.localhost');
        $this->assertSame('tenant', \DB::connection()->getConfig()['name']);
    }

    /** @test */
    public function tenant_is_persisted_before_the_created_hook_is_called()
    {
        $was_persisted = false;

        Tenancy::eventListener('tenant.created', function ($tenancy, $tenant) use (&$was_persisted) {
            $was_persisted = $tenant->persisted;
        });

        Tenant::new()->save();

        $this->assertTrue($was_persisted);
    }
}
