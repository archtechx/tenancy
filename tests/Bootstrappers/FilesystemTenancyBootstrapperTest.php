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

test('tenant storage gets deleted during tenant deletion when the DeletingTenant pipeline contains DeleteTenantStorage', function() {
    Event::listen(DeletingTenant::class,
        JobPipeline::make([DeleteTenantStorage::class])->send(function (DeletingTenant $event) {
            return $event->tenant;
        })->shouldBeQueued(false)->toListener()
    );

    $centralStoragePath = storage_path();
    tenancy()->initialize(Tenant::create());

    // FilesystemTenancyBootstrapper not enabled,
    // tenant and central storage path is the same,
    // the storage deletion will be skipped.
    $tenantStoragePath = storage_path();
    expect($tenantStoragePath)->toBe($centralStoragePath);
    expect(File::isDirectory($centralStoragePath))->toBeTrue();
    tenant()->delete();

    expect(File::isDirectory($centralStoragePath))->toBeTrue();

    config([
        'tenancy.bootstrappers' => [FilesystemTenancyBootstrapper::class],
        'tenancy.filesystem.suffix_storage_path' => false,
    ]);

    tenancy()->initialize(Tenant::create());

    $tenantStoragePath = storage_path();

    // FilesystemTenancyBootstrapper enabled,
    // but tenant and central storage path is still the same
    // because suffix_storage_path is false.
    // The storage deletion will be skipped.
    expect($tenantStoragePath)->toBe($centralStoragePath);
    expect(File::isDirectory($centralStoragePath))->toBeTrue();
    tenant()->delete();

    expect(File::isDirectory($centralStoragePath))->toBeTrue();

    config([
        'tenancy.bootstrappers' => [FilesystemTenancyBootstrapper::class],
        'tenancy.filesystem.suffix_storage_path' => true,
    ]);

    tenancy()->initialize(Tenant::create());
    $tenantStoragePath = storage_path();

    // FilesystemTenancyBootstrapper enabled,
    // suffix_storage_path enabled, so the two paths are distinct.
    // Tenant storage will be deleted.
    expect($tenantStoragePath)->not()->toBe($centralStoragePath);
    expect(File::isDirectory($tenantStoragePath))->toBeTrue();

    tenant()->delete();

    expect(File::isDirectory($tenantStoragePath))->toBeFalse();
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

test('scoped disks are scoped per tenant', function () {
    config([
        'tenancy.bootstrappers' => [
            FilesystemTenancyBootstrapper::class,
        ],
        'filesystems.disks.scoped_disk' => [
            'driver' => 'scoped',
            'disk' => 'public',
            'prefix' => 'scoped_disk_prefix',
        ],
    ]);

    $tenant = Tenant::create();

    Storage::disk('scoped_disk')->put('foo.txt', 'central');
    expect(Storage::disk('scoped_disk')->get('foo.txt'))->toBe('central');
    expect(file_get_contents(storage_path() . "/app/public/scoped_disk_prefix/foo.txt"))->toBe('central');

    tenancy()->initialize($tenant);

    expect(Storage::disk('scoped_disk')->get('foo.txt'))->toBe(null);
    Storage::disk('scoped_disk')->put('foo.txt', 'tenant');
    expect(file_get_contents(storage_path() . "/app/public/scoped_disk_prefix/foo.txt"))->toBe('tenant');
    expect(Storage::disk('scoped_disk')->get('foo.txt'))->toBe('tenant');

    tenancy()->end();

    expect(Storage::disk('scoped_disk')->get('foo.txt'))->toBe('central');
    Storage::disk('scoped_disk')->put('foo.txt', 'central2');
    expect(Storage::disk('scoped_disk')->get('foo.txt'))->toBe('central2');

    expect(file_get_contents(storage_path() . "/app/public/scoped_disk_prefix/foo.txt"))->toBe('central2');
    expect(file_get_contents(storage_path() . "/tenant{$tenant->id}/app/public/scoped_disk_prefix/foo.txt"))->toBe('tenant');
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
