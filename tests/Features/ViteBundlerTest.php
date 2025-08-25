<?php

declare(strict_types=1);

use Illuminate\Foundation\Vite;
use Illuminate\Support\Facades\Event;
use Stancl\Tenancy\Actions\CloneRoutesAsTenant;
use Stancl\Tenancy\Bootstrappers\FilesystemTenancyBootstrapper;
use Stancl\Tenancy\Events\TenancyEnded;
use Stancl\Tenancy\Events\TenancyInitialized;
use Stancl\Tenancy\Features\ViteBundler;
use Stancl\Tenancy\Listeners\BootstrapTenancy;
use Stancl\Tenancy\Listeners\RevertToCentralContext;
use Stancl\Tenancy\Tests\Etc\Tenant;

beforeEach(function () {
    config([
        'tenancy.filesystem.asset_helper_override' => true,
        'tenancy.bootstrappers' => [FilesystemTenancyBootstrapper::class],
    ]);
});

test('vite bundler ensures vite assets use global_asset when asset_helper_override is enabled', function () {
    config(['tenancy.features' => [ViteBundler::class]]);

    app(CloneRoutesAsTenant::class)->handle();

    Event::listen(TenancyInitialized::class, BootstrapTenancy::class);
    Event::listen(TenancyEnded::class, RevertToCentralContext::class);

    tenancy()->initialize(Tenant::create());

    // Not what we want
    expect(asset('foo'))->toBe(route('stancl.tenancy.asset', ['path' => 'foo']));

    $viteAssetUrl = app(Vite::class)->asset('foo');
    $expectedGlobalUrl = global_asset('build/assets/foo-AbC123.js');

    expect($viteAssetUrl)->toBe($expectedGlobalUrl);
    expect($viteAssetUrl)->toBe('http://localhost/build/assets/foo-AbC123.js');
});
