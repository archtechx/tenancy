<?php

use Stancl\JobPipeline\JobPipeline;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Stancl\Tenancy\Tests\Etc\Tenant;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;
use Stancl\Tenancy\Events\TenancyEnded;
use Stancl\Tenancy\Events\TenantCreated;
use Stancl\Tenancy\Events\TenantDeleted;
use Stancl\Tenancy\Events\DeletingTenant;
use Stancl\Tenancy\Events\TenancyInitialized;
use Stancl\Tenancy\Jobs\CreateStorageSymlinks;
use Stancl\Tenancy\Jobs\RemoveStorageSymlinks;
use Stancl\Tenancy\Listeners\BootstrapTenancy;
use Stancl\Tenancy\Jobs\DeleteTenantStorage;
use Stancl\Tenancy\Listeners\RevertToCentralContext;
use Stancl\Tenancy\Bootstrappers\FilesystemTenancyBootstrapper;
use function Stancl\Tenancy\Tests\pest;

beforeEach(function () {
    Event::listen(TenancyInitialized::class, BootstrapTenancy::class);
    Event::listen(TenancyEnded::class, RevertToCentralContext::class);
});

test('local storage public urls are generated correctly', function () {
    config([
        'tenancy.bootstrappers' => [
            FilesystemTenancyBootstrapper::class,
        ],
        'tenancy.filesystem.root_override.public' => '%storage_path%/app/public/',
        'tenancy.filesystem.url_override.public' => 'public-%tenant%'
    ]);

    $tenant1 = Tenant::create();
    $tenant2 = Tenant::create();
    $tenant1StorageUrl = 'http://localhost/public-' . $tenant1->getKey().'/';
    $tenant2StorageUrl = 'http://localhost/public-' . $tenant2->getKey().'/';

    tenancy()->initialize($tenant1);

    $this->assertEquals(
        $tenant1StorageUrl,
        Storage::disk('public')->url('')
    );

    Storage::disk('public')->put($tenant1FileName = 'tenant1.txt', 'text');

    $this->assertEquals(
        $tenant1StorageUrl . $tenant1FileName,
        Storage::disk('public')->url($tenant1FileName)
    );

    tenancy()->initialize($tenant2);

    $this->assertEquals(
        $tenant2StorageUrl,
        Storage::disk('public')->url('')
    );

    Storage::disk('public')->put($tenant2FileName = 'tenant2.txt', 'text');

    $this->assertEquals(
        $tenant2StorageUrl . $tenant2FileName,
        Storage::disk('public')->url($tenant2FileName)
    );
});

test('files can get fetched using the storage url', function() {
    config([
        'tenancy.bootstrappers' => [
            FilesystemTenancyBootstrapper::class,
        ],
        'tenancy.filesystem.root_override.public' => '%storage_path%/app/public/',
        'tenancy.filesystem.url_override.public' => 'public-%tenant%'
    ]);

    $tenant1 = Tenant::create();
    $tenant2 = Tenant::create();

    pest()->artisan('tenants:link');

    // First tenant
    tenancy()->initialize($tenant1);
    Storage::disk('public')->put($tenantFileName = 'tenant1.txt', $tenantKey = $tenant1->getTenantKey());

    $url = Storage::disk('public')->url($tenantFileName);
    $tenantDiskName = str(config('tenancy.filesystem.url_override.public'))->replace('%tenant%', $tenantKey);
    $hostname = str($url)->before($tenantDiskName);
    $parsedUrl = str($url)->after($hostname);

    expect(file_get_contents(public_path($parsedUrl)))->toBe($tenantKey);

    // Second tenant
    tenancy()->initialize($tenant2);
    Storage::disk('public')->put($tenantFileName = 'tenant2.txt', $tenantKey = $tenant2->getTenantKey());

    $url = Storage::disk('public')->url($tenantFileName);
    $tenantDiskName = str(config('tenancy.filesystem.url_override.public'))->replace('%tenant%', $tenantKey);
    $hostname = str($url)->before($tenantDiskName);
    $parsedUrl = str($url)->after($hostname);

    expect(file_get_contents(public_path($parsedUrl)))->toBe($tenantKey);

    // Central
    tenancy()->end();
    Storage::disk('public')->put($centralFileName = 'central.txt', $centralFileContent = 'central');

    pest()->artisan('storage:link');
    $url = Storage::disk('public')->url($centralFileName);

    expect(file_get_contents(public_path($url)))->toBe($centralFileContent);
});

test('storage_path helper does not change if suffix_storage_path is off', function() {
    $originalStoragePath = storage_path();

    config([
        'tenancy.bootstrappers' => [FilesystemTenancyBootstrapper::class],
        'tenancy.filesystem.suffix_storage_path' => false,
    ]);

    tenancy()->initialize(Tenant::create());

    $this->assertEquals($originalStoragePath, storage_path());
});

test('links to storage disks with a configured root are suffixed if not overridden', function() {
    config([
        'filesystems.disks.public.root' => 'http://sample-s3-url.com/my-app',
        'tenancy.bootstrappers' => [
            FilesystemTenancyBootstrapper::class,
        ],
        'tenancy.filesystem.root_override.public' => null,
        'tenancy.filesystem.url_override.public' => null,
    ]);

    $tenant = Tenant::create();

    $expectedStoragePath = storage_path() . '/tenant' . $tenant->getTenantKey(); // /tenant = suffix base

    tenancy()->initialize($tenant);

    // Check suffixing logic
    expect(storage_path())->toEqual($expectedStoragePath);
});

test('create and delete storage symlinks jobs work', function() {
    Event::listen(
        TenantCreated::class,
        JobPipeline::make([CreateStorageSymlinks::class])->send(function (TenantCreated $event) {
            return $event->tenant;
        })->toListener()
    );

    Event::listen(
        TenantDeleted::class,
        JobPipeline::make([RemoveStorageSymlinks::class])->send(function (TenantDeleted $event) {
            return $event->tenant;
        })->toListener()
    );

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

    tenancy()->initialize($tenant);

    $tenantKey = $tenant->getTenantKey();

    $this->assertDirectoryExists(storage_path("app/public"));
    $this->assertEquals(storage_path("app/public/"), readlink(public_path("public-$tenantKey")));

    $tenant->delete();

    $this->assertDirectoryDoesNotExist(public_path("public-$tenantKey"));
});

test('tenant storage gets deleted during tenant deletion when the DeletingTenant pipeline contains DeleteTenantStorage', function(bool $suffixStoragePath) {
    Event::listen(DeletingTenant::class,
        JobPipeline::make([DeleteTenantStorage::class])->send(function (DeletingTenant $event) {
            return $event->tenant;
        })->shouldBeQueued(false)->toListener()
    );

    config([
        'tenancy.bootstrappers' => [FilesystemTenancyBootstrapper::class],
        // suffix_storage_path only affects the storage_path() helper.
        // The disks are scoped to the tenant's storage directory either way,
        // so the tenant files end up there.
        'tenancy.filesystem.suffix_storage_path' => $suffixStoragePath,
        // This is the default tenancy config -- set it here explicitly for clarity
        'tenancy.filesystem.suffix_base' => 'tenant',
        'tenancy.filesystem.root_override.public' => '%storage_path%/app/public/',
    ]);

    $centralStoragePath = storage_path();
    $tenant = Tenant::create();
    $tenantStoragePath = $centralStoragePath . "/tenant{$tenant->getTenantKey()}";

    tenancy()->initialize($tenant);

    Storage::disk('public')->put('foo.txt', 'tenant file');
    expect(file_get_contents($tenantStoragePath . '/app/public/foo.txt'))->toBe('tenant file');

    $tenant->delete();

    expect(File::isDirectory($tenantStoragePath))->toBeFalse();
    expect(File::isDirectory($centralStoragePath))->toBeTrue();
})->with([true, false]);

test('DeleteTenantStorage does not delete the central storage directory when the filesystem bootstrapper is disabled', function () {
    config(['tenancy.bootstrappers' => []]);

    $centralStoragePath = storage_path();
    $tenant = Tenant::create();

    (new DeleteTenantStorage($tenant))->handle();

    expect(File::isDirectory($centralStoragePath))->toBeTrue();
});

test('the framework/cache directory is created when storage_path is scoped', function (bool $suffixStoragePath) {
    config([
        'tenancy.bootstrappers' => [
            FilesystemTenancyBootstrapper::class,
        ],
        'tenancy.filesystem.suffix_storage_path' => $suffixStoragePath
    ]);

    $centralStoragePath = storage_path();

    tenancy()->initialize($tenant = Tenant::create());

    if ($suffixStoragePath) {
        expect(storage_path('framework/cache'))->toBe($centralStoragePath . "/tenant{$tenant->id}/framework/cache");
        expect(is_dir($centralStoragePath . "/tenant{$tenant->id}/framework/cache"))->toBeTrue();
    } else {
        expect(storage_path('framework/cache'))->toBe($centralStoragePath . '/framework/cache');
        expect(is_dir($centralStoragePath . "/tenant{$tenant->id}/framework/cache"))->toBeFalse();
    }
})->with([true, false]);

test('scoped disks are scoped per tenant', function (bool $nested) {
    config([
        'tenancy.bootstrappers' => [
            FilesystemTenancyBootstrapper::class,
        ],
        'filesystems.disks.scoped_disk' => [
            'driver' => 'scoped',
            'disk' => 'public',
            'prefix' => 'scoped_disk_prefix',
        ],
        'filesystems.disks.nested_disk' => [
            'driver' => 'scoped',
            'disk' => 'scoped_disk',
            'prefix' => 'nested_disk_prefix',
        ],
    ]);

    $disk = $nested ? 'nested_disk' : 'scoped_disk';
    $prefix = $nested ? 'scoped_disk_prefix/nested_disk_prefix' : 'scoped_disk_prefix';

    $tenant = Tenant::create();

    Storage::disk($disk)->put('foo.txt', 'central');

    config(['filesystem.disks.public.prefix' => 'scoped_disk_prefix']);

    expect(Storage::disk($disk)->get('foo.txt'))->toBe('central');
    expect(file_get_contents(storage_path() . "/app/public/{$prefix}/foo.txt"))->toBe('central');

    tenancy()->initialize($tenant);

    expect(Storage::disk($disk)->get('foo.txt'))->toBe(null);
    Storage::disk($disk)->put('foo.txt', 'tenant');
    expect(file_get_contents(storage_path() . "/app/public/{$prefix}/foo.txt"))->toBe('tenant');
    expect(Storage::disk($disk)->get('foo.txt'))->toBe('tenant');

    tenancy()->end();

    expect(Storage::disk($disk)->get('foo.txt'))->toBe('central');
    Storage::disk($disk)->put('foo.txt', 'central2');
    expect(Storage::disk($disk)->get('foo.txt'))->toBe('central2');

    expect(file_get_contents(storage_path() . "/app/public/{$prefix}/foo.txt"))->toBe('central2');
    expect(file_get_contents(storage_path() . "/tenant{$tenant->id}/app/public/{$prefix}/foo.txt"))->toBe('tenant');
})->with([
    'scoped disk' => false,
    'nested scoped disk' => true,
]);

test('scoped disks based on a non-local disk are scoped per tenant', function () {
    config([
        'tenancy.bootstrappers' => [
            FilesystemTenancyBootstrapper::class,
        ],
        'filesystems.disks.scoped_s3' => [
            'driver' => 'scoped',
            'disk' => 's3',
            'prefix' => 'scoped_s3_prefix',
        ],
        'tenancy.filesystem.disks' => ['s3'], // As long as the base disk (s3) is listed here, the scoped disk will be scoped
    ]);

    expect(Storage::disk('s3')->path('foo.txt'))->toBe('foo.txt');
    expect(Storage::disk('scoped_s3')->path('foo.txt'))->toBe('scoped_s3_prefix/foo.txt');

    $tenant = Tenant::create();
    tenancy()->initialize($tenant);

    expect(Storage::disk('s3')->path('foo.txt'))->toBe("tenant{$tenant->id}/foo.txt");
    expect(Storage::disk('scoped_s3')->path('foo.txt'))->toBe("tenant{$tenant->id}/scoped_s3_prefix/foo.txt");

    tenancy()->end();

    expect(Storage::disk('scoped_s3')->path('foo.txt'))->toBe('scoped_s3_prefix/foo.txt');
});

test('adding a scoped disk to tenancy.filesystem.disks throws an exception if its base disk is not listed', function (string $disk) {
    config([
        'tenancy.bootstrappers' => [
            FilesystemTenancyBootstrapper::class,
        ],
        'filesystems.disks.foo' => [
            'driver' => 'scoped',
            'disk' => 'public',
            'prefix' => 'foo',
        ],
        'filesystems.disks.bar' => [
            'driver' => 'scoped',
            'disk' => 'foo',
            'prefix' => 'bar',
        ],
        // Scoped disk with an inline parent
        'filesystems.disks.inline_parent' => [
            'driver' => 'scoped',
            'disk' => [
                'driver' => 'local',
                'root' => storage_path('app/inline'),
            ],
            'prefix' => 'inline_parent',
        ],
        'tenancy.filesystem.disks' => [$disk],
    ]);

    expect(fn () => tenancy()->initialize(Tenant::create()))
        ->toThrow(Exception::class, "Disk [$disk] uses the 'scoped' driver, so it has no root to make tenant-aware.");

    // 'inline_parent' has no base disk name to list, so there's no way to make it tenant-aware
    // and the exception is thrown regardless of what's listed.
    if ($disk !== 'inline_parent') {
        config(['tenancy.filesystem.disks' => ['public', $disk]]);

        expect(fn () => tenancy()->initialize(Tenant::create()))
            ->not()->toThrow(Exception::class, "Disk [$disk] uses the 'scoped' driver, so it has no root to make tenant-aware.");
    }
})->with([
    'scoped disk' => 'foo',
    'nested scoped disk' => 'bar',
    'scoped disk with an inline base disk' => 'inline_parent',
]);

test('adding a scoped disk to tenancy.filesystem.disks has no effect on the disk when its base disk is listed too', function () {
    config([
        'tenancy.bootstrappers' => [
            FilesystemTenancyBootstrapper::class,
        ],
        'filesystems.disks.foo' => [
            'driver' => 'scoped',
            'disk' => 'public',
            'prefix' => 'foo',
        ],
        // Only the scoped disk's parent/base disk ('public') has to be listed here.
        // Listing 'foo' too is redundant, and it shouldn't change anything.
        'tenancy.filesystem.disks' => ['local', 'public', 'foo'],
    ]);

    $tenant = Tenant::create();
    tenancy()->initialize($tenant);

    // The 'foo' disk's parent disk ('public') is tenant-aware, so its root is scoped the same way.
    expect(Storage::disk('foo')->path('testing.txt'))->toBe(storage_path('app/public/foo/testing.txt'));

    // Scoped disks have no root or url of their own, so the bootstrapper leaves their config alone
    expect(config('filesystems.disks.foo'))->toBe([
        'driver' => 'scoped',
        'disk' => 'public',
        'prefix' => 'foo',
    ]);
});

test('file cache stores get their paths scoped on bootstrap and restored back on revert', function () {
    $fooPath = storage_path('framework/cache/foo_file');
    $barPath = storage_path('framework/cache/bar_file');
    File::deleteDirectory($fooPath);
    File::deleteDirectory($barPath);

    // Use separate 'foo_file'/'bar_file' stores rather than reconfiguring 'file'.
    // TestCase::setUp() calls `cache:clear file`, which resolves the 'file' store and leaves it
    // in the CacheManager with the default path. A later config() call can't mutate that,
    // so in central context, cache would be written to 'storage/framework/cache/data' no matter how the config changes.
    // The same applies to the other tests below that configure separate cache stores rather than using 'file'.
    config([
        'tenancy.bootstrappers' => [
            FilesystemTenancyBootstrapper::class,
        ],
        'tenancy.cache.stores' => ['foo_file', 'bar_file'],
        'cache.stores.foo_file' => [
            'driver' => 'file',
            'path' => $fooPath,
        ],
        'cache.stores.bar_file' => [
            'driver' => 'file',
            'path' => $barPath,
        ],
    ]);

    Cache::store('foo_file')->put('key', 'central foo');
    Cache::store('bar_file')->put('key', 'central bar');

    tenancy()->initialize(Tenant::create());

    Cache::store('foo_file')->put('key', 'tenant foo');
    Cache::store('bar_file')->put('key', 'tenant bar');

    // Each store uses its own scoped path.
    // The stores don't read or overwrite each other's entries.
    expect(Cache::store('foo_file')->get('key'))->toBe('tenant foo');
    expect(Cache::store('bar_file')->get('key'))->toBe('tenant bar');

    Cache::store('bar_file')->flush();

    // Only bar_file was flushed
    expect(Cache::store('foo_file')->get('key'))->toBe('tenant foo');
    expect(Cache::store('bar_file')->get('key'))->toBeNull();

    tenancy()->end();

    // revert() points each store back at its original configured path
    expect(Cache::store('foo_file')->get('key'))->toBe('central foo');
    expect(Cache::store('bar_file')->get('key'))->toBe('central bar');
});

test('only file driver cache stores get scoped', function () {
    $centralStoragePath = storage_path();
    $fooPath = storage_path('framework/cache/foo_file');
    File::deleteDirectory($fooPath);

    config([
        'tenancy.bootstrappers' => [
            FilesystemTenancyBootstrapper::class,
        ],
        'cache.stores.foo_file' => [
            'driver' => 'file',
            'path' => $fooPath,
        ],
        // Only stores that use the 'file' driver are scoped.
        // The 'redis' store won't be scoped since it doesn't use the 'file' driver,
        // and 'nonexistent_store' won't be scoped since it just doesn't exist.
        'tenancy.cache.stores' => ['foo_file', 'redis', 'nonexistent_store'],
    ]);

    Cache::store('redis')->put('key', 'central');

    tenancy()->initialize($tenant = Tenant::create());

    // Only the file store's path gets scoped
    expect(config('cache.stores.foo_file.path'))->toBe("{$centralStoragePath}/tenant{$tenant->id}/framework/cache/foo_file");
    expect(config('cache.stores.redis'))->not()->toHaveKey('path');
    expect(config('cache.stores.nonexistent_store'))->toBeNull();

    // The 'redis' store doesn't use the file driver (no path to scope),
    // so FilesystemTenancyBootstrapper skips it in scopeCache().
    // The central value is retained in the tenant context.
    expect(Cache::store('redis')->get('key'))->toBe('central');

    tenancy()->end();
});

test('cache scoping can be toggled using the scope_cache config', function (bool $scopeCache) {
    $fooPath = storage_path('framework/cache/foo_file');
    File::deleteDirectory($fooPath);

    config([
        'tenancy.bootstrappers' => [
            FilesystemTenancyBootstrapper::class,
        ],
        'tenancy.cache.stores' => ['foo_file'],
        'cache.stores.foo_file' => [
            'driver' => 'file',
            'path' => $fooPath,
        ],
        'tenancy.filesystem.scope_cache' => $scopeCache,
    ]);

    Cache::store('foo_file')->put('key', 'central');

    tenancy()->initialize(Tenant::create());

    if ($scopeCache) {
        expect(config('cache.stores.foo_file.path'))->not()->toBe($fooPath);
        expect(Cache::store('foo_file')->get('key'))->toBe(null);
    } else {
        // The store keeps using its central path, so the cache is shared between contexts
        expect(config('cache.stores.foo_file.path'))->toBe($fooPath);
        expect(Cache::store('foo_file')->get('key'))->toBe('central');
    }

    Cache::store('foo_file')->put('key', 'written in tenant context');

    tenancy()->end();

    if ($scopeCache) {
        expect(Cache::store('foo_file')->get('key'))->toBe('central');
    } else {
        expect(Cache::store('foo_file')->get('key'))->toBe('written in tenant context');
    }
})->with([true, false]);

test('scopeCache ignores changes to tenancy.cache.stores made in tenant context', function () {
    $fooPath = storage_path('framework/cache/foo_file');
    $barPath = storage_path('framework/cache/bar_file');
    File::deleteDirectory($fooPath);
    File::deleteDirectory($barPath);

    config([
        'tenancy.bootstrappers' => [
            FilesystemTenancyBootstrapper::class,
        ],
        'cache.stores.foo_file' => [
            'driver' => 'file',
            'path' => $fooPath,
        ],
        'cache.stores.bar_file' => [
            'driver' => 'file',
            'path' => $barPath,
        ],
        // Only foo_file is scoped during bootstrap()
        'tenancy.cache.stores' => ['foo_file'],
    ]);

    Cache::store('foo_file')->put('key', 'central');
    Cache::store('bar_file')->put('key', 'central');

    tenancy()->initialize(Tenant::create());

    Cache::store('foo_file')->put('key', 'tenant');

    // Mutate tenancy.cache.stores in tenant context (remove 'foo_file', add 'bar_file')
    config(['tenancy.cache.stores' => ['bar_file']]);
    // 'bar_file' wasn't in tenancy.cache.stores during bootstrap.
    // No original path is captured for that store, so it continues to use its central path.
    expect(Cache::store('bar_file')->get('key'))->toBe('central');
    Cache::store('bar_file')->put('key', 'written in tenant context');

    tenancy()->end();

    // revert() restores the paths for the stores that were scoped during bootstrap
    expect(Cache::store('foo_file')->get('key'))->toBe('central');
    // 'bar_file' was never scoped, it still reads from the same path it was writing to in tenant context
    expect(Cache::store('bar_file')->get('key'))->toBe('written in tenant context');
});

test('a configured lock_path is scoped separately from path', function () {
    // A store can configure path and lock_path as two different directories
    $centralStoragePath = storage_path();
    $path = "{$centralStoragePath}/framework/cache/foo";
    $lockPath = "{$centralStoragePath}/framework/cache/foo_locks";

    // The paths are hardcoded, so delete the directories before running the assertions.
    // Cache::store('foo_file')->lock(...) below defaults to a lock that never expires,
    // and this test never releases it, so a stale lock left over from
    // a previous run would make lock() fail forever.
    File::deleteDirectory($path);
    File::deleteDirectory($lockPath);

    config([
        'tenancy.bootstrappers' => [
            FilesystemTenancyBootstrapper::class,
        ],
        'tenancy.cache.stores' => ['foo_file'],
        'cache.stores.foo_file' => [
            'driver' => 'file',
            'path' => $path,
            'lock_path' => $lockPath,
        ],
    ]);

    $tenant = Tenant::create();
    tenancy()->initialize($tenant);

    $tenantPath = "{$centralStoragePath}/tenant{$tenant->id}/framework/cache/foo";
    $tenantLockPath = "{$centralStoragePath}/tenant{$tenant->id}/framework/cache/foo_locks";

    // lock('foo')->get() acquires the lock and creates the lock file at $tenantLockPath
    expect(Cache::store('foo_file')->lock('foo')->get())->toBeTrue();

    expect(File::isDirectory($tenantLockPath))->toBeTrue();
    // The lock file was created at $tenantLockPath, not $tenantPath
    expect(File::isDirectory($tenantPath))->toBeFalse();

    tenancy()->end();

    // The lock was only acquired in the tenant context, so in the central context,
    // it's free (and the lock file doesn't exist in the central context).
    expect(File::isDirectory($lockPath))->toBeFalse();
    // Acquire the lock in the central context
    expect(Cache::store('foo_file')->lock('foo')->get())->toBeTrue();
    // The lock file was created at the original (central) lock_path
    expect(File::isDirectory($lockPath))->toBeTrue();
});

test('a file cache store without a configured lock_path defaults to using its scoped path for locks', function () {
    // 'lock_path' is optional in the file store config.
    // FileStore falls back to using 'path' for locks when 'lock_path' is not specified in the config (or set to null).
    // scopeCache() respects the original config and leaves lock_path unset/null.
    $centralStoragePath = storage_path();
    $path = "{$centralStoragePath}/framework/cache/foo";

    // $path is hardcoded here. The directory created at $path in a previous run can persist,
    // so delete the directory at $path first.
    File::deleteDirectory($path);

    config([
        'tenancy.bootstrappers' => [
            FilesystemTenancyBootstrapper::class,
        ],
        'tenancy.cache.stores' => ['foo_file'],
        'cache.stores.foo_file' => [
            'driver' => 'file',
            'path' => $path,
            // 'lock_path' not set (same behavior as if it were set to null)
        ],
    ]);

    $tenant = Tenant::create();
    tenancy()->initialize($tenant);

    $tenantPath = "{$centralStoragePath}/tenant{$tenant->id}/framework/cache/foo";

    // Initializing tenancy scopes the store's 'path', but respects the configured
    // (unset) 'lock_path' and leaves it alone.
    expect(config('cache.stores.foo_file.path'))->toBe($tenantPath);
    expect(config('cache.stores.foo_file.lock_path'))->toBeNull();

    // With no 'lock_path' configured, the lock file will be created in the scoped 'path' directory
    expect(File::isDirectory($tenantPath))->toBeFalse();
    expect(Cache::store('foo_file')->lock('foo')->get())->toBeTrue();
    expect(File::isDirectory($tenantPath))->toBeTrue();

    // The lock is still held in the tenant context, so acquiring it again fails
    expect(Cache::store('foo_file')->lock('foo')->get())->toBeFalse();

    tenancy()->end();

    // The same 'foo' lock is free in central context.
    // revert() points the 'path' back to the original.
    expect(File::isDirectory($path))->toBeFalse();
    expect(Cache::store('foo_file')->lock('foo')->get())->toBeTrue();
    expect(File::isDirectory($path))->toBeTrue();
});

test('a cache store using a path not based on storage_path() is scoped to a tenant subdirectory', function () {
    // tenantScopedPath() has no central storage path prefix to swap for the tenant's here,
    // so it appends the tenant suffix to $path instead.
    $path = '/tmp/tenancy-cache-test';
    File::deleteDirectory($path);

    config([
        'tenancy.bootstrappers' => [
            FilesystemTenancyBootstrapper::class,
        ],
        'tenancy.cache.stores' => ['foo_file'],
        'cache.stores.foo_file' => [
            'driver' => 'file',
            'path' => $path,
        ],
    ]);

    $tenant1 = Tenant::create();
    $tenant2 = Tenant::create();

    tenancy()->initialize($tenant1);
    Cache::store('foo_file')->put('key', 'tenant1');

    tenancy()->initialize($tenant2);
    expect(Cache::store('foo_file')->get('key'))->toBeNull();

    tenancy()->initialize($tenant1);
    expect(Cache::store('foo_file')->get('key'))->toBe('tenant1');

    // Tenant's 'foo_file' cache directory is created inside $path,
    // not at the tenant-scoped storage_path().
    expect(File::isDirectory("{$path}/tenant{$tenant1->id}"))->toBeTrue();
    expect(File::isDirectory(storage_path('framework/cache/data')))->toBeFalse();

    tenancy()->end();
});
