<?php

declare(strict_types=1);

use Illuminate\Foundation\Vite;
use Stancl\Tenancy\Bootstrappers\FilesystemTenancyBootstrapper;
use Stancl\Tenancy\Features\ViteBundler;
use Stancl\Tenancy\Tests\Etc\Tenant;

use function Stancl\Tenancy\Tests\withBootstrapping;

beforeEach(function () {
    config([
        'tenancy.filesystem.asset_helper_override' => true,
        'tenancy.bootstrappers' => [FilesystemTenancyBootstrapper::class],
    ]);
});

test('vite bundler ensures vite assets use global_asset when asset_helper_override is enabled', function () {
    config(['tenancy.features' => [ViteBundler::class]]);

    withBootstrapping();

    tenancy()->initialize(Tenant::create());

    // Not what we want
    expect(asset('foo'))->toBe(route('stancl.tenancy.asset', ['path' => 'foo']));

    $viteAssetUrl = app(Vite::class)->asset('foo');
    $expectedGlobalUrl = global_asset('build/assets/foo-AbC123.js');

    expect($viteAssetUrl)->toBe($expectedGlobalUrl);
    expect($viteAssetUrl)->toBe('http://localhost/build/assets/foo-AbC123.js');
});
