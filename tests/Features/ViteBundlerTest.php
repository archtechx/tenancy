<?php

declare(strict_types=1);

use Illuminate\Foundation\Vite;
use Stancl\Tenancy\Tests\Etc\Tenant;
use Stancl\Tenancy\Vite as StanclVite;
use Stancl\Tenancy\Features\ViteBundler;
use Stancl\Tenancy\TenancyServiceProvider;

test('vite helper uses our custom class', function() {
    $vite = app(Vite::class);

    expect($vite)->toBeInstanceOf(Vite::class);
    expect($vite)->not()->toBeInstanceOf(StanclVite::class);

    config([
        'tenancy.features' => [ViteBundler::class],
    ]);

    TenancyServiceProvider::bootstrapFeatures();

    $tenant = Tenant::create();

    tenancy()->initialize($tenant);

    app()->forgetInstance(Vite::class);

    $vite = app(Vite::class);

    expect($vite)->toBeInstanceOf(StanclVite::class);
});
