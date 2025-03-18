<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Event;
use Stancl\Tenancy\Events\TenancyEnded;
use Stancl\Tenancy\Database\Models\Tenant;
use Stancl\Tenancy\Events\TenancyInitialized;
use Stancl\Tenancy\Listeners\BootstrapTenancy;
use Stancl\Tenancy\Listeners\RevertToCentralContext;
use Stancl\Tenancy\Actions\CreateStorageSymlinksAction;
use Stancl\Tenancy\Actions\RemoveStorageSymlinksAction;
use Stancl\Tenancy\Bootstrappers\FilesystemTenancyBootstrapper;
use Illuminate\Support\Facades\File;

beforeEach(function () {
    Event::listen(TenancyInitialized::class, BootstrapTenancy::class);
    Event::listen(TenancyEnded::class, RevertToCentralContext::class);
});

// todo@move move these to be in the same file as the other tests from this PR (#909) rather than generic "action tests"

test('create storage symlinks action works', function() {
    config([
        'tenancy.bootstrappers' => [
            FilesystemTenancyBootstrapper::class,
        ],
        'tenancy.filesystem.suffix_base' => 'tenant-',
        'tenancy.filesystem.root_override.public' => '%storage_path%/app/public/',
        'tenancy.filesystem.url_override.public' => 'public-%tenant%'
    ]);

    /** @var Tenant $tenant */
    $tenant = Tenant::create();
    $tenantKey = $tenant->getTenantKey();

    tenancy()->initialize($tenant);

    // The symlink doesn't exist
    expect(is_link($publicPath = public_path("public-$tenantKey")))->toBeFalse();
    expect(file_exists($publicPath))->toBeFalse();

    (new CreateStorageSymlinksAction)($tenant);

    // The symlink exists and is valid
    expect(is_link($publicPath = public_path("public-$tenantKey")))->toBeTrue();
    expect(file_exists($publicPath))->toBeTrue();
    $this->assertEquals(storage_path("app/public/"), readlink($publicPath));
});

test('remove storage symlinks action works', function() {
    config([
        'tenancy.bootstrappers' => [
            FilesystemTenancyBootstrapper::class,
        ],
        'tenancy.filesystem.suffix_base' => 'tenant-',
        'tenancy.filesystem.root_override.public' => '%storage_path%/app/public/',
        'tenancy.filesystem.url_override.public' => 'public-%tenant%'
    ]);

    /** @var Tenant $tenant */
    $tenant = Tenant::create();
    $tenantKey = $tenant->getTenantKey();

    tenancy()->initialize($tenant);

    (new CreateStorageSymlinksAction)($tenant);

    // The symlink exists and is valid
    expect(is_link($publicPath = public_path("public-$tenantKey")))->toBeTrue();
    expect(file_exists($publicPath))->toBeTrue();

    (new RemoveStorageSymlinksAction)($tenant);

    // The symlink doesn't exist
    expect(is_link($publicPath))->toBeFalse();
    expect(file_exists($publicPath))->toBeFalse();
});

test('removing tenant symlinks works even if the symlinks are invalid', function() {
    config([
        'tenancy.bootstrappers' => [
            FilesystemTenancyBootstrapper::class,
        ],
        'tenancy.filesystem.suffix_base' => 'tenant-',
        'tenancy.filesystem.root_override.public' => '%storage_path%/app/public/',
        'tenancy.filesystem.url_override.public' => 'public-%tenant%'
    ]);

    /** @var Tenant $tenant */
    $tenant = Tenant::create();
    $tenantKey = $tenant->getTenantKey();

    tenancy()->initialize($tenant);

    (new CreateStorageSymlinksAction)($tenant);

    // The symlink exists and is valid
    expect(is_link($publicPath = public_path("public-$tenantKey")))->toBeTrue();
    expect(file_exists($publicPath))->toBeTrue();

    // Make the symlink invalid by deleting the tenant storage directory
    $storagePath = storage_path();
    File::deleteDirectory($storagePath);

    // The symlink still exists, but isn't valid
    expect(is_link($publicPath))->toBeTrue();
    expect(file_exists($publicPath))->toBeFalse();

    (new RemoveStorageSymlinksAction)($tenant);

    expect(is_link($publicPath))->toBeFalse();
});
