<?php

declare(strict_types=1);

namespace Stancl\Tenancy\Tests\Features;

use Stancl\Tenancy\Tests\TestCase;

class TenantConfigTest extends TestCase
{
    public $autoInitTenancy = false;
    public $autoCreateTenant = false;

    /** @test */
    public function config_is_merged_and_removed()
    {
        $this->assertSame(null, config('services.paypal'));
        config([
            'tenancy.storage_to_config_map' => [
                'paypal_api_public' => 'services.paypal.public',
                'paypal_api_private' => 'services.paypal.private',
            ],
            'tenancy.features' => ['Stancl\Tenancy\Features\TenantConfig'],
        ]);

        tenancy()->create('foo.localhost', [
            'paypal_api_public' => 'foo',
            'paypal_api_private' => 'bar',
        ]);

        tenancy()->init('foo.localhost');
        $this->assertSame(['public' => 'foo', 'private' => 'bar'], config('services.paypal'));

        tenancy()->end();
        $this->assertSame([
            'public' => null,
            'private' => null,
        ], config('services.paypal'));
    }
}
