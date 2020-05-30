<?php

declare(strict_types=1);

namespace Stancl\Tenancy\Tests\Features;

use Illuminate\Support\Facades\Event;
use Stancl\Tenancy\Events\TenancyEnded;
use Stancl\Tenancy\Events\TenancyInitialized;
use Stancl\Tenancy\Features\TenantConfig;
use Stancl\Tenancy\Listeners\BootstrapTenancy;
use Stancl\Tenancy\Listeners\RevertToCentralContext;
use Stancl\Tenancy\Tests\Etc\Tenant;
use Stancl\Tenancy\Tests\TestCase;

class TenantConfigTest extends TestCase
{
    public function tearDown(): void
    {
        TenantConfig::$storageToConfigMap = [];

        parent::tearDown();
    }

    /** @test */
    public function config_is_merged_and_removed()
    {
        $this->assertSame(null, config('services.paypal'));
        config([
            'tenancy.features' => [TenantConfig::class],
            'tenancy.bootstrappers' => [],
        ]);
        Event::listen(TenancyInitialized::class, BootstrapTenancy::class);
        Event::listen(TenancyEnded::class, RevertToCentralContext::class);

        TenantConfig::$storageToConfigMap = [
            'paypal_api_public' => 'services.paypal.public',
            'paypal_api_private' => 'services.paypal.private',
        ];

        $tenant = Tenant::create([
            'paypal_api_public' => 'foo',
            'paypal_api_private' => 'bar',
        ]);

        tenancy()->initialize($tenant);
        $this->assertSame(['public' => 'foo', 'private' => 'bar'], config('services.paypal'));

        tenancy()->end();
        $this->assertSame([
            'public' => null,
            'private' => null,
        ], config('services.paypal'));
    }

    /** @test */
    public function the_value_can_be_set_to_multiple_config_keys()
    {
        $this->assertSame(null, config('services.paypal'));
        config([
            'tenancy.features' => [TenantConfig::class],
            'tenancy.bootstrappers' => [],
        ]);
        Event::listen(TenancyInitialized::class, BootstrapTenancy::class);
        Event::listen(TenancyEnded::class, RevertToCentralContext::class);

        TenantConfig::$storageToConfigMap = [
            'paypal_api_public' => [
                'services.paypal.public1',
                'services.paypal.public2',
            ],
            'paypal_api_private' => 'services.paypal.private',
        ];

        $tenant = Tenant::create([
            'paypal_api_public' => 'foo',
            'paypal_api_private' => 'bar',
        ]);

        tenancy()->initialize($tenant);
        $this->assertSame([
            'public1' => 'foo',
            'public2' => 'foo',
            'private' => 'bar',
        ], config('services.paypal'));

        tenancy()->end();
        $this->assertSame([
            'public1' => null,
            'public2' => null,
            'private' => null,
        ], config('services.paypal'));
    }
}
