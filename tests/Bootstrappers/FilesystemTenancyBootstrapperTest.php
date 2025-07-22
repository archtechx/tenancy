<?php

use Stancl\JobPipeline\JobPipeline;
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
use Stancl\Tenancy\Listeners\DeleteTenantStorage;
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

    // todo@tests https://github.com/tenancy-for-laravel/v4/pull/44#issue-2228530362

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

test('tenant storage can get deleted after the tenant when DeletingTenant listens to DeleteTenantStorage', function() {
    Event::listen(DeletingTenant::class, DeleteTenantStorage::class);

    tenancy()->initialize(Tenant::create());
    $tenantStoragePath = storage_path();

    Storage::fake('test');

    expect(File::isDirectory($tenantStoragePath))->toBeTrue();

    Storage::put('test.txt', 'testing file');

    tenant()->delete();

    expect(File::isDirectory($tenantStoragePath))->toBeFalse();
});
