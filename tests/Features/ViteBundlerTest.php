<?php

declare(strict_types=1);

use Illuminate\Foundation\Vite as FoundationVite;
use Illuminate\Support\Facades\App;
use Stancl\Tenancy\Features\ViteBundler;
use Stancl\Tenancy\Tests\Etc\Tenant;
use Stancl\Tenancy\Vite as StanclVite;


test('replaces the vite helper instance with custom class', function () {
    $vite = app(\Illuminate\Foundation\Vite::class);

    expect($vite)->toBeInstanceOf(FoundationVite::class);

    expect($vite)->not->toBeInstanceOf(StanclVite::class);

    config([
        'tenancy.features' => [ViteBundler::class],
    ]);

    $tenant = Tenant::create();

    tenancy()->initialize($tenant);

    app()->forgetInstance(\Illuminate\Foundation\Vite::class);

    $vite = app(\Illuminate\Foundation\Vite::class);

    expect($vite)->toBeInstanceOf(StanclVite::class);
});
