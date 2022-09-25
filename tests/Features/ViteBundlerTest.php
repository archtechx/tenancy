<?php

declare(strict_types=1);

use Illuminate\Foundation\Vite as FoundationVite;
use Illuminate\Support\Facades\App;
use Stancl\Tenancy\Features\ViteBundler;
use Stancl\Tenancy\Tests\Etc\Tenant;
use Stancl\Tenancy\Vite as StanclVite;


test('replaces the vite helper instance with custom class', function () {
    $concreteBindings = App::getBindings()[FoundationVite::class]['concrete'];

    expect($concreteBindings(App::getInstance()))
        ->toBeInstanceOf(FoundationVite::class);

    expect($concreteBindings(App::getInstance()))
        ->not->toBeInstanceOf(StanclVite::class);

    config([
        'tenancy.features' => [ViteBundler::class],
    ]);

    $tenant = Tenant::create();

    tenancy()->initialize($tenant);

    $concreteBindings = App::getBindings()[FoundationVite::class]['concrete'];

    expect($concreteBindings)
        ->toBeCallable();

    expect($concreteBindings(App::getInstance()))
        ->toBeInstanceOf(FoundationVite::class);

    expect($concreteBindings(App::getInstance()))
        ->toBeInstanceOf(StanclVite::class);

    tenancy()->end();
});