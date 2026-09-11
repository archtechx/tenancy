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

    RemoveStorageSymlinksAction::$removeNestedDirectories = false;
});

afterEach(function () {
    RemoveStorageSymlinksAction::$removeNestedDirectories = false;
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

test('disks with a prefix are symlinked correctly', function (string $prefix) {
    config([
        'tenancy.bootstrappers' => [
            FilesystemTenancyBootstrapper::class,
        ],
        'tenancy.filesystem.suffix_base' => 'tenant-',
        'tenancy.filesystem.root_override.public' => '%storage_path%/app/public/',
        'tenancy.filesystem.url_override.public' => 'public-%tenant%',
        'filesystems.disks.public.prefix' => $prefix,
    ]);

    /** @var Tenant $tenant */
    $tenant = Tenant::create();
    $tenantKey = $tenant->getTenantKey();

    // possibleTenantSymlinks() trims the prefix, do the same here for the assertions to be accurate
    $prefix = trim($prefix, '/');

    tenancy()->initialize($tenant);

    Storage::disk('public')->put('foo.txt', 'tenant file');

    (new CreateStorageSymlinksAction)($tenant);

    expect(Storage::disk('public')->url('foo.txt'))->toBe("http://localhost/public-{$tenantKey}/{$prefix}/foo.txt");
    expect(readlink(public_path("public-{$tenantKey}/{$prefix}")))->toBe(storage_path("app/public/{$prefix}"));
    expect(file_get_contents(public_path("public-{$tenantKey}/{$prefix}/foo.txt")))->toBe('tenant file');
})->with(['abc', 'abc/def', '/abc/def/']);

test('symlinks of prefixed disks only expose the prefixed directory', function () {
    config([
        'tenancy.bootstrappers' => [
            FilesystemTenancyBootstrapper::class,
        ],
        'tenancy.filesystem.root_override.public' => '%storage_path%/app/public/',
        'tenancy.filesystem.url_override.public' => 'public-%tenant%',
        'filesystems.disks.public.prefix' => 'abc',
    ]);

    /** @var Tenant $tenant */
    $tenant = Tenant::create();
    $tenantKey = $tenant->getTenantKey();

    tenancy()->initialize($tenant);

    Storage::disk('public')->put('foo.txt', 'tenant file');

    // The disk cannot reach this file, neither should the symlink
    File::put(storage_path('app/public/sibling.txt'), 'file next to the prefixed directory');

    (new CreateStorageSymlinksAction)($tenant);

    expect(file_exists(public_path("public-{$tenantKey}/sibling.txt")))->toBeFalse();
    expect(file_get_contents(public_path("public-{$tenantKey}/abc/foo.txt")))->toBe('tenant file');
});

test('removing a prefixed disk symlink removes the directories created for it', function () {
    RemoveStorageSymlinksAction::$removeNestedDirectories = true;

    config([
        'tenancy.bootstrappers' => [
            FilesystemTenancyBootstrapper::class,
        ],
        'tenancy.filesystem.root_override.public' => '%storage_path%/app/public/',
        'tenancy.filesystem.url_override.public' => 'public-%tenant%',
        'filesystems.disks.public.prefix' => 'abc/def',
    ]);

    /** @var Tenant $tenant */
    $tenant = Tenant::create();
    $tenantKey = $tenant->getTenantKey();

    tenancy()->initialize($tenant);

    Storage::disk('public')->put('foo.txt', 'tenant file');

    (new CreateStorageSymlinksAction)($tenant);

    $symlink = public_path("public-{$tenantKey}/abc/def");
    $diskRoot = readlink($symlink);

    (new RemoveStorageSymlinksAction)($tenant);

    // The symlink and every directory created for it are deleted
    expect(is_link($symlink))->toBeFalse();
    expect(file_exists(public_path("public-{$tenantKey}")))->toBeFalse();
    // public_path() itself is not deleted
    expect(is_dir(public_path()))->toBeTrue();
    // The directory that the symlink points to is untouched
    expect(file_get_contents($diskRoot . '/foo.txt'))->toBe('tenant file');
});

test('non-empty directories are not removed with the symlink', function () {
    RemoveStorageSymlinksAction::$removeNestedDirectories = true;

    config([
        'tenancy.bootstrappers' => [
            FilesystemTenancyBootstrapper::class,
        ],
        'tenancy.filesystem.root_override.public' => '%storage_path%/app/public/',
        'tenancy.filesystem.url_override.public' => 'public-%tenant%',
        'filesystems.disks.public.prefix' => 'abc/def',
    ]);

    /** @var Tenant $tenant */
    $tenant = Tenant::create();
    $tenantKey = $tenant->getTenantKey();

    tenancy()->initialize($tenant);

    (new CreateStorageSymlinksAction)($tenant);

    File::put(public_path("public-{$tenantKey}/abc/actual-file.txt"), 'foo');

    (new RemoveStorageSymlinksAction)($tenant);

    expect(is_link(public_path("public-{$tenantKey}/abc/def")))->toBeFalse();
    expect(file_get_contents(public_path("public-{$tenantKey}/abc/actual-file.txt")))->toBe('foo');
});
