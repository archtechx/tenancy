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
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Event::listen(TenancyInitialized::class, BootstrapTenancy::class);
    Event::listen(TenancyEnded::class, RevertToCentralContext::class);
});

test('create storage symlinks action works', function (string|null $rootOverride, bool $suffixStoragePath) {
    config([
        'tenancy.bootstrappers' => [
            FilesystemTenancyBootstrapper::class,
        ],
        'tenancy.filesystem.suffix_base' => 'tenant-',
        // The disk root is suffixed regardless of the suffix_storage_path config
        'tenancy.filesystem.suffix_storage_path' => $suffixStoragePath,
        'tenancy.filesystem.root_override.public' => $rootOverride,
        'tenancy.filesystem.url_override' => [
            'public' => 'public-%tenant%',
            // Disks with a falsy url_override are skipped
            'local' => '',
        ],
    ]);

    /** @var Tenant $tenant */
    $tenant = Tenant::create();
    $tenantKey = $tenant->getTenantKey();

    tenancy()->initialize($tenant);

    // The symlink doesn't exist
    expect(is_link($publicPath = public_path("public-$tenantKey")))->toBeFalse();
    expect(file_exists($publicPath))->toBeFalse();

    Storage::disk('public')->put('foo.txt', 'tenant file');

    (new CreateStorageSymlinksAction)($tenant);

    // The symlink exists and points to the directory the tenant's disk writes to
    expect(is_link($publicPath))->toBeTrue();
    expect(readlink($publicPath))->toBe(config('filesystems.disks.public.root'));
    expect(file_get_contents($publicPath . '/foo.txt'))->toBe('tenant file');

    // The local disk is skipped because its url_override is '' -- no symlink is created at public_path('')
    expect(is_link(public_path('')))->toBeFalse();
})->with([
    'default root_override' => ['%storage_path%/app/public/', true],
    'suffix_storage_path disabled' => ['%storage_path%/app/public/', false],
    'custom root_override' => ['%original_storage_path%/app/public/%tenant%/', true],
    // Without a root_override, the bootstrapper suffixes the disk's central root
    'no root_override' => [null, true],
]);

test('create storage symlinks action fails for disks that are not tenant-aware', function () {
    config([
        'tenancy.bootstrappers' => [
            FilesystemTenancyBootstrapper::class,
        ],
        // The public disk has both overrides configured, but it is not in
        // tenancy.filesystem.disks, so the bootstrapper never scopes its root.
        // Symlinking it would point every tenant's public path to the central disk root.
        'tenancy.filesystem.disks' => ['local'],
        'tenancy.filesystem.root_override.public' => '%storage_path%/app/public/',
        'tenancy.filesystem.url_override.public' => 'public-%tenant%',
    ]);

    /** @var Tenant $tenant */
    $tenant = Tenant::create();

    expect(fn () => (new CreateStorageSymlinksAction)($tenant))
        ->toThrow(Exception::class, 'Disk public is not tenant-aware.');

    expect(is_link(public_path('public-' . $tenant->getTenantKey())))->toBeFalse();
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
