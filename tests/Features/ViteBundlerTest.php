<?php

declare(strict_types=1);

use Illuminate\Foundation\Vite as FoundationVite;
use Stancl\Tenancy\Features\ViteBundler;
use Stancl\Tenancy\Tests\Etc\Tenant;
use Stancl\Tenancy\Tests\TestCase;
use Stancl\Tenancy\Vite as StanclVite;

class ViteBundlerTest extends TestCase
{
    /** @test */
    public function the_vite_helper_uses_our_custom_class()
    {
        if (version_compare(app()->version(), '9.0', '<')) {
            $this->markTestSkipped('Vite is only used in Laravel 9+');
        }

        $vite = app(\Illuminate\Foundation\Vite::class);

        $this->assertInstanceOf(FoundationVite::class, $vite);
        $this->assertNotInstanceOf(StanclVite::class, $vite);

        config([
            'tenancy.features' => [ViteBundler::class],
        ]);

        $tenant = Tenant::create();

        tenancy()->initialize($tenant);

        app()->forgetInstance(\Illuminate\Foundation\Vite::class);

        $vite = app(\Illuminate\Foundation\Vite::class);

        $this->assertInstanceOf(StanclVite::class, $vite);
    }
}
