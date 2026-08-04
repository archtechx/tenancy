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

test('file cache stores are separated per tenant', function () {
    config([
        'tenancy.bootstrappers' => [
            FilesystemTenancyBootstrapper::class,
        ],
        // Only stores that use the 'file' driver are scoped.
        // The 'redis' store won't be scoped since it doesn't use the 'file' driver,
        // and 'nonexistent_store' won't be scoped since it just doesn't exist.
        'tenancy.cache.stores' => ['file', 'redis', 'nonexistent_store'],
        // Laravel's default 'file' store config (set explicitly here just for clarity).
        'cache.stores.file' => [
            'driver' => 'file',
            'path' => storage_path('framework/cache/data'),
            'lock_path' => storage_path('framework/cache/data'),
        ],
    ]);

    $tenant1 = Tenant::create();
    $tenant2 = Tenant::create();

    Cache::store('file')->put('key', 'central');
    Cache::store('redis')->put('key', 'central');

    // 'redis' and 'nonexistent_store' are skipped before scopeCache() attempts to read their config.
    // Without the skipping logic, the `$this->originalCachePaths[$name] = $store['path']`
    // line in scopeCache() would throw an ErrorException (with 'redis', we'd get an
    // 'Undefined array key "path"' exception, and with 'nonexistent_store',
    // we'd get 'Trying to access array offset on null').
    expect(fn () => tenancy()->initialize($tenant1))->not()->toThrow(ErrorException::class);

    expect(Cache::store('file')->get('key'))->toBeNull();
    Cache::store('file')->put('key', 'tenant1');

    // The 'redis' store doesn't use the file driver (no path to scope),
    // so FilesystemTenancyBootstrapper skips it in scopeCache().
    // The central value is retained in the tenant context.
    expect(Cache::store('redis')->get('key'))->toBe('central');

    tenancy()->initialize($tenant2);

    expect(Cache::store('file')->get('key'))->toBeNull();
    Cache::store('file')->put('key', 'tenant2');

    tenancy()->initialize($tenant1);

    expect(Cache::store('file')->get('key'))->toBe('tenant1');

    tenancy()->end();

    expect(Cache::store('file')->get('key'))->toBe('central');

    // Turn FilesystemTenancyBootstrapper's cache scoping off
    config(['tenancy.filesystem.scope_cache' => false]);

    tenancy()->initialize($tenant1);

    expect(Cache::store('file')->get('key'))->toBe('central');
    Cache::store('file')->put('key', 'written in tenant context');

    tenancy()->end();

    expect(Cache::store('file')->get('key'))->toBe('written in tenant context');
});

test('central cache is not lost when tenancy ends', function () {
    $path = storage_path('framework/cache/foo_file');
    $barPath = storage_path('framework/cache/bar_file');
    File::deleteDirectory($path);
    File::deleteDirectory($barPath);

    // Use a separate 'foo' store rather than reconfiguring 'file'.
    // TestCase::setUp() calls `cache:clear file`, which resolves the 'file' store and leaves it
    // in the CacheManager with the default path. A later config() call can't mutate that, so a test
    // reconfiguring 'file' would keep using storage/framework/cache/data and pass either way.
    // The same applies to the other tests below that configure separate cache stores rather than using 'file'.
    config([
        'tenancy.bootstrappers' => [
            FilesystemTenancyBootstrapper::class,
        ],
        'cache.stores.foo_file' => [
            'driver' => 'file',
            'path' => $path,
            'lock_path' => $path,
        ],
        'cache.stores.bar_file' => [
            'driver' => 'file',
            'path' => $barPath,
            'lock_path' => $barPath,
        ],
        // Only include foo_file in tenancy.cache.stores,
        // leave bar_file excluded (= not scoped by FilesystemTenancyBootstrapper) for now.
        'tenancy.cache.stores' => ['foo_file'],
    ]);

    $tenant = Tenant::create();

    Cache::store('foo_file')->put('foo', 'central');

    // Just initialize and revert tenancy to trigger FilesystemTenancyBootstrapper
    tenancy()->initialize($tenant);

    // bar_file wasn't in tenancy.cache.stores during bootstrap, so no original path was stored for it,
    // and it's left with its configured path (not scoped).
    config(['tenancy.cache.stores' => ['foo_file', 'bar_file']]);
    Cache::store('bar_file')->put('bar', 'not scoped');

    tenancy()->end();

    // Nothing deleted the 'foo' entry, so its value should stay 'central' even after reverting tenancy.
    // FilesystemTenancyBootstrapper::revert() makes the store use its original configured path.
    expect(Cache::store('foo_file')->get('foo'))->toBe('central');
    expect(Cache::store('bar_file')->get('bar'))->toBe('not scoped');

    File::deleteDirectory($path);
    File::deleteDirectory($barPath);
});

test('file cache stores with different configured paths do not share a directory', function () {
    $fooPath = storage_path('framework/cache/foo');
    $barPath = storage_path('framework/cache/bar');

    File::deleteDirectory($fooPath);
    File::deleteDirectory($barPath);

    config([
        'tenancy.bootstrappers' => [
            FilesystemTenancyBootstrapper::class,
        ],
        'tenancy.cache.stores' => ['foo_file', 'bar_file'],
        'cache.stores.foo_file' => [
            'driver' => 'file',
            'path' => $fooPath,
            'lock_path' => $fooPath,
        ],
        'cache.stores.bar_file' => [
            'driver' => 'file',
            'path' => $barPath,
            'lock_path' => $barPath,
        ],
    ]);

    tenancy()->initialize(Tenant::create());

    Cache::store('foo_file')->put('key', 'foo');
    Cache::store('bar_file')->put('key', 'bar');

    // Each store uses its own directory in the tenant's context, so they don't read or overwrite
    // each other's entries, and flushing one doesn't empty the other.
    expect(Cache::store('foo_file')->get('key'))->toBe('foo');
    expect(Cache::store('bar_file')->get('key'))->toBe('bar');

    Cache::store('bar_file')->flush();

    expect(Cache::store('foo_file')->get('key'))->toBe('foo');

    tenancy()->end();

    File::deleteDirectory($fooPath);
    File::deleteDirectory($barPath);
});

test('a configured lock_path is scoped separately from path', function () {
    // A store can configure path and lock_path as two different directories.
    // Locks should use lock_path IF CONFIGURED, not fall back into the same directory as path.
    $centralStoragePath = storage_path();
    $path = "{$centralStoragePath}/framework/cache/foo";
    $lockPath = "{$centralStoragePath}/framework/cache/foo_locks";

    // Delete the directories before running assertions, not just after.
    // Cache::lock() below defaults to a lock that never expires,
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

    expect(Cache::store('foo_file')->lock('foo')->get())->toBeTrue();

    $tenantPath = "{$centralStoragePath}/tenant{$tenant->id}/framework/cache/foo";
    $tenantLockPath = "{$centralStoragePath}/tenant{$tenant->id}/framework/cache/foo_locks";

    // Taking the lock creates the tenant's scoped lock_path directory.
    // Nothing wrote a cache entry, so the tenant's scoped path directory doesn't exist (path and lock_path are scoped separately).
    expect(File::isDirectory($tenantLockPath))->toBeTrue();
    expect(File::isDirectory($tenantPath))->toBeFalse();

    tenancy()->end();

    expect(Cache::store('foo_file')->lock('foo')->get())->toBeTrue();

    // After reverting, locks go back to the configured central lock_path.
    expect(File::isDirectory($lockPath))->toBeTrue();

    File::deleteDirectory($path);
    File::deleteDirectory($lockPath);
});

test('a cache store without a configured lock_path is scoped without error', function () {
    // lock_path is optional -- Laravel falls back to using path for locks when it's not set.
    // This test covers that path through scopeCache() specifically (which none of the other tests exercise).
    $path = storage_path('framework/cache/foo');
    File::deleteDirectory($path);

    config([
        'tenancy.bootstrappers' => [
            FilesystemTenancyBootstrapper::class,
        ],
        'tenancy.cache.stores' => ['foo_file'],
        'cache.stores.foo_file' => [
            'driver' => 'file',
            'path' => $path,
            // No 'lock_path' key at all.
        ],
    ]);

    tenancy()->initialize(Tenant::create());

    expect(Cache::store('foo_file')->put('key', 'tenant'))->toBeTrue();
    expect(Cache::store('foo_file')->lock('foo')->get())->toBeTrue();

    tenancy()->end();

    expect(Cache::store('foo_file')->put('key', 'central'))->toBeTrue();
    expect(Cache::store('foo_file')->lock('foo')->get())->toBeTrue();

    File::deleteDirectory($path);
});

test('a cache store using a path not based on storage_path() is suffixed in place, not moved under the tenant storage path', function () {
    // $path below doesn't start with the central storage path, so tenantCachePath() has no storage
    // path prefix to swap for the tenant's -- it appends the tenant suffix directly to $path instead.
    // Check that tenant isolation still works for a store configured like this, and that its cache
    // ends up at $path itself, not under the tenant's storage path.
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
            'lock_path' => $path,
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

    expect(File::isDirectory("{$path}/tenant{$tenant1->id}"))->toBeTrue();

    // storage_path() is already scoped to tenant1 here (storagePath() runs before scopeCache() in
    // bootstrap()), so this is the tenant's default cache directory. Nothing should be there, since
    // foo_file is configured with its own $path that isn't storage_path()-based.
    expect(File::isDirectory(storage_path('framework/cache/data')))->toBeFalse();

    tenancy()->end();

    File::deleteDirectory($path);
});
