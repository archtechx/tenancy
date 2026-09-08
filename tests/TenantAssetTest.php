<?php

declare(strict_types=1);

use Stancl\Tenancy\Tests\Etc\Tenant;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Stancl\Tenancy\Actions\CloneRoutesAsTenant;
use Stancl\Tenancy\Events\TenancyInitialized;
use Stancl\Tenancy\Listeners\BootstrapTenancy;
use Stancl\Tenancy\Middleware\InitializeTenancyByPath;
use Stancl\Tenancy\Middleware\InitializeTenancyByRequestData;
use Stancl\Tenancy\Bootstrappers\FilesystemTenancyBootstrapper;
use Stancl\Tenancy\Bootstrappers\UrlGeneratorBootstrapper;
use Stancl\Tenancy\Controllers\TenantAssetController;
use Stancl\Tenancy\Events\TenancyEnded;
use Stancl\Tenancy\Listeners\RevertToCentralContext;
use Stancl\Tenancy\Overrides\TenancyUrlGenerator;
use function Stancl\Tenancy\Tests\pest;

beforeEach(function () {
    config(['tenancy.bootstrappers' => [
        FilesystemTenancyBootstrapper::class,
    ]]);

    TenancyUrlGenerator::$prefixRouteNames = false;
    TenancyUrlGenerator::$passTenantParameterToRoutes = true;
    TenantAssetController::$headers = [];
    TenantAssetController::$publicDisk = null;
    InitializeTenancyByRequestData::$onFail = null;

    /** @var CloneRoutesAsTenant $cloneAction */
    $cloneAction = app(CloneRoutesAsTenant::class);
    $cloneAction->handle(Route::getRoutes()->getByName('stancl.tenancy.asset'));

    Event::listen(TenancyInitialized::class, BootstrapTenancy::class);
    Event::listen(TenancyEnded::class, RevertToCentralContext::class);
});

afterEach(function () {
    TenantAssetController::$headers = [];
    TenantAssetController::$publicDisk = null;
    InitializeTenancyByRequestData::$onFail = null;
});

test('asset can be accessed using the url returned by the tenant asset helper', function () {
    config(['tenancy.identification.default_middleware' => InitializeTenancyByRequestData::class]);

    $tenant = Tenant::create();
    tenancy()->initialize($tenant);

    $filename = 'testfile' . Str::random(8);
    Storage::disk('public')->put($filename, 'bar');
    $path = storage_path("app/public/$filename");

    // response()->file() returns BinaryFileResponse whose content is
    // inaccessible via getContent, so ->assertSee() can't be used
    expect($path)->toBeFile();
    $response = pest()->get(tenant_asset($filename), [
        'X-Tenant' => $tenant->id,
    ]);

    $response->assertSuccessful();

    $f = fopen($path, 'r');
    $content = fread($f, filesize($path));
    fclose($f);

    expect($content)->toBe('bar');
});

test('tenant assets are served even when the suffix_storage_path config is set to false', function () {
    config([
        'tenancy.identification.default_middleware' => InitializeTenancyByRequestData::class,
        'tenancy.filesystem.suffix_storage_path' => false,
    ]);

    // With suffix_storage_path disabled, storage_path() stays central in tenant context
    $centralStoragePath = storage_path();

    $tenant = Tenant::create();
    tenancy()->initialize($tenant);

    $filename = 'testfile' . Str::random(8);
    Storage::disk('public')->put($filename, 'bar');

    $response = pest()->get(tenant_asset($filename), ['X-Tenant' => $tenant->id]);

    // The asset is served from the tenant's storage directory, not from the central storage path
    $response->assertSuccessful();
    expect($response->getFile()->getPathname())
        ->toBe("$centralStoragePath/tenant{$tenant->id}/app/public/$filename");
});

test('the disk used for serving tenant assets is configurable', function () {
    config([
        'tenancy.identification.default_middleware' => InitializeTenancyByRequestData::class,
        // This is tenancy's default override for the local disk's root, set it here for clarity
        'tenancy.filesystem.root_override.local' => '%storage_path%/app/',
    ]);

    // The local disk's root is overridden to '%storage_path%/app/' (so it does not use 'app/public')
    TenantAssetController::$publicDisk = 'local';

    $tenant = Tenant::create();
    tenancy()->initialize($tenant);

    $filename = 'testfile' . Str::random(8);
    Storage::disk('local')->put($filename, 'bar');
    $path = Storage::disk('local')->path($filename);

    $response = pest()->get(tenant_asset($filename), ['X-Tenant' => $tenant->id]);

    // The asset is served from the disk's root instead of 'app/public'
    $response->assertSuccessful();
    expect($response->getFile()->getPathname())->toBe($path);
});

test('tenant asset controller throws when the configured disk is not local or not tenant-aware', function () {
    config([
        'tenancy.identification.default_middleware' => InitializeTenancyByRequestData::class,
        // Add a disk that uses the s3 driver (= non-local disk).
        // Use dummy credentials so that the s3 disk can be resolved without throwing an AWS exception.
        'filesystems.disks.remote' => [
            'driver' => 's3',
            'region' => 'us-east-1',
            'key' => 'key',
            'secret' => 'secret',
            'bucket' => 'bucket',
        ],
        'filesystems.disks.scoped_remote' => [
            'driver' => 'scoped',
            'disk' => 'remote',
            'prefix' => 'assets',
        ],
        // 'media' isn't tenant-aware (i.e. not included in tenancy.filesystem.disks)
        'filesystems.disks.media' => [
            'driver' => 'local',
            'root' => storage_path('app/media'),
        ],
        'filesystems.disks.scoped_media' => [
            'driver' => 'scoped',
            'disk' => 'media',
            'prefix' => 'assets',
        ],
        'filesystems.disks.inline_scoped_media' => [
            'driver' => 'scoped',
            // Laravel allows configuring the parent disk inline, but such a disk
            // has no name, so it cannot be listed in tenancy.filesystem.disks (i.e. made tenant-aware)
            'disk' => [
                'driver' => 'local',
                'root' => storage_path('app/media'),
            ],
            'prefix' => 'assets',
        ],
    ]);

    $tenant = Tenant::create();
    tenancy()->initialize($tenant);

    $this->withoutExceptionHandling();

    $expectedExceptions = [
        'remote' => 'Disk [remote] is not a local disk.',
        'scoped_remote' => 'Disk [scoped_remote] is not a local disk.',
        'media' => 'Disk [media] is not tenant-aware.',
        'scoped_media' => 'Disk [media] is not tenant-aware.',
        'inline_scoped_media' => 'Disk [inline_scoped_media] has an unnamed parent disk.',
    ];

    foreach ($expectedExceptions as $publicDisk => $exceptionMessage) {
        TenantAssetController::$publicDisk = $publicDisk;

        expect(fn () => pest()->get(tenant_asset('foo.txt'), ['X-Tenant' => $tenant->id]))
            ->toThrow(Exception::class, $exceptionMessage);
    }
});

test('tenant assets are served from the resolved root of the configured disk', function () {
    config([
        'tenancy.identification.default_middleware' => InitializeTenancyByRequestData::class,
        // A scoped disk has no configured root -- it inherits the root of its parent disk
        // and appends its prefix to it, both of which happens when the disk is resolved.
        'filesystems.disks.scoped_disk' => [
            'driver' => 'scoped',
            'disk' => 'public',
            'prefix' => 'scoped_disk_prefix',
        ],
        // A prefix is part of the disk's full path, so it has to be included in the asset root
        'filesystems.disks.prefixed' => [
            'driver' => 'local',
            'root' => storage_path('app/media'),
            'prefix' => 'foo_prefix',
        ],
        'tenancy.filesystem.disks' => ['local', 'public', 'prefixed'],
        'tenancy.filesystem.root_override.prefixed' => '%storage_path%/app/media/',
    ]);

    $tenant = Tenant::create();
    tenancy()->initialize($tenant);

    foreach ([
        'scoped_disk' => 'app/public/scoped_disk_prefix',
        'prefixed' => 'app/media/foo_prefix',
    ] as $publicDisk => $expectedRoot) {
        TenantAssetController::$publicDisk = $publicDisk;

        $filename = 'testfile' . Str::random(8);
        Storage::disk($publicDisk)->put($filename, 'bar');
        $path = Storage::disk($publicDisk)->path($filename);

        expect($path)->toBe(storage_path("{$expectedRoot}/$filename"));

        $response = pest()->get(tenant_asset($filename), ['X-Tenant' => $tenant->id]);

        $response->assertSuccessful();
        expect($response->getFile()->getPathname())->toBe($path);
    }
});

test('tenant assets are served from the central storage path in central context', function () {
    config(['tenancy.identification.default_middleware' => InitializeTenancyByRequestData::class]);

    // Mimic the universal route setup (let the request through even though no tenant is identified)
    InitializeTenancyByRequestData::$onFail = fn ($e, $request, $next) => $next($request);

    $filename = 'testfile' . Str::random(8);
    Storage::disk('public')->put($filename, 'bar');

    $response = pest()->get(tenant_asset($filename));

    $response->assertSuccessful();
    expect($response->getFile()->getPathname())->toBe(storage_path("app/public/$filename"));
});

test('asset helper returns a link to tenant asset controller when asset url is null', function () {
    config(['app.asset_url' => null]);
    config(['tenancy.filesystem.asset_helper_override' => true]);

    $tenant = Tenant::create();
    tenancy()->initialize($tenant);

    expect(asset('foo'))->toBe(route('stancl.tenancy.asset', ['path' => 'foo']));

    tenancy()->end();
    expect(asset('foo'))->toBe('http://localhost/foo');
});

test('asset helper returns a link to an external url when asset url is not null', function () {
    config(['app.asset_url' => 'https://an-s3-bucket']);
    config(['tenancy.filesystem.asset_helper_override' => true]);

    $tenant = Tenant::create();
    tenancy()->initialize($tenant);

    expect(asset('foo'))->toBe("https://an-s3-bucket/tenant{$tenant->id}/foo");

    tenancy()->end();
    expect(asset('foo'))->toBe('https://an-s3-bucket/foo');
});

test('asset helper works correctly with path identification', function (bool $kernelIdentification) {
    TenancyUrlGenerator::$prefixRouteNames = true;
    TenancyUrlGenerator::$passTenantParameterToRoutes = true;

    config(['tenancy.filesystem.asset_helper_override' => true]);
    config(['tenancy.identification.default_middleware' => InitializeTenancyByPath::class]);
    config(['tenancy.bootstrappers' => array_merge([UrlGeneratorBootstrapper::class], config('tenancy.bootstrappers'))]);

    $tenantAssetRoute = Route::prefix('{tenant}')->get('/tenant_helper', function () {
        return tenant_asset('foo');
    })->name('tenant.helper.tenant');

    $assetRoute = Route::prefix('{tenant}')->get('/asset_helper', function () {
        return asset('foo');
    })->name('tenant.helper.asset');

    if ($kernelIdentification) {
        app(Kernel::class)->pushMiddleware(InitializeTenancyByPath::class);
    } else {
        $assetRoute->middleware(InitializeTenancyByPath::class);
        $tenantAssetRoute->middleware(InitializeTenancyByPath::class);
    }

    /** @var CloneRoutesAsTenant $cloneAction */
    $cloneAction = app(CloneRoutesAsTenant::class);

    $cloneAction->handle();

    tenancy()->initialize(Tenant::create());

    expect(pest()->get(route('tenant.helper.asset'))->getContent())->toBe(route('stancl.tenancy.asset', ['path' => 'foo']));
    expect(pest()->get(route('tenant.helper.tenant'))->getContent())->toBe(route('stancl.tenancy.asset', ['path' => 'foo']));
})->with([
    'kernel identification' => true,
    'route-level identification' => false,
]);

test('TenantAssetController headers are configurable', function () {
    TenantAssetController::$headers = function (Request $request) {
        return ['X-Foo' => 'Bar'];
    };

    $tenant = Tenant::create();
    tenancy()->initialize($tenant);
    $tenant->createDomain('foo.localhost');

    $filename = 'testfile' . Str::random(10);
    Storage::disk('public')->put($filename, 'bar');

    $this->withoutExceptionHandling();

    $response = pest()->get("http://foo.localhost/tenancy/assets/$filename", [
        'X-Tenant' => $tenant->id,
    ]);

    $response->assertSuccessful();
    $response->assertHeader('X-Foo', 'Bar');
});

test('global asset helper returns the same url regardless of tenancy initialization', function () {
    $original = global_asset('foobar');
    expect(global_asset('foobar'))->toBe(asset('foobar'));

    $tenant = Tenant::create();
    tenancy()->initialize($tenant);

    expect(global_asset('foobar'))->toBe($original);
});

test('asset helper tenancy can be disabled', function () {
    $original = asset('foo');

    config([
        'app.asset_url' => null,
        'tenancy.filesystem.asset_helper_override' => false,
    ]);

    $tenant = Tenant::create();
    tenancy()->initialize($tenant);

    expect(asset('foo'))->toBe($original);
});

test('test asset controller returns a 404 when no path is provided', function () {
    config(['tenancy.identification.default_middleware' => InitializeTenancyByRequestData::class]);

    $tenant = Tenant::create();

    tenancy()->initialize($tenant);

    $this->withoutExceptionHandling();
    pest()->expectExceptionMessage('Empty path'); // outside tests this is a 404

    pest()->get(tenant_asset(null), [
        'X-Tenant' => $tenant->id,
    ])->assertNotFound();
});

test('tenant asset controller returns a 404 when the storage root doesnt exist', function () {
    config(['tenancy.identification.default_middleware' => InitializeTenancyByRequestData::class]);

    $tenant = Tenant::create();

    tenancy()->initialize($tenant);

    $storageRoot = storage_path("app/public");

    if (is_dir($storageRoot)) {
        rmdir(storage_path("app/public"));
    }

    $this->withoutExceptionHandling();
    pest()->expectExceptionMessage("Storage root doesn't exist"); // outside tests this is a 404

    pest()->get(tenant_asset('foo.txt'), [
        'X-Tenant' => $tenant->id,
    ]);
});

test('tenant asset controller returns a 404 when accessing a nonexistent file', function () {
    config(['tenancy.identification.default_middleware' => InitializeTenancyByRequestData::class]);

    $tenant = Tenant::create();

    tenancy()->initialize($tenant);

    $storageRoot = storage_path("app/public");

    if (! is_dir($storageRoot)) {
        mkdir(storage_path("app/public"), recursive: true);
    }

    $this->withoutExceptionHandling();
    pest()->expectExceptionMessage("Accessing a nonexistent file"); // outside tests this is a 404

    pest()->get(tenant_asset('foo.txt'), [
        'X-Tenant' => $tenant->id,
    ]);
});

test('tenant asset controller only serves files inside the asset root', function () {
    config(['tenancy.identification.default_middleware' => InitializeTenancyByRequestData::class]);

    $tenant = Tenant::create();
    tenancy()->initialize($tenant);

    Storage::disk('public')->put('photo.jpg', 'public file');

    pest()->get(tenant_asset('photo.jpg'), ['X-Tenant' => $tenant->id])->assertSuccessful();

    // Files outside the asset root, e.g. ones that shouldn't be served.
    // The directory with the second file starts with the name of the asset root.
    file_put_contents(storage_path('app/photo.jpg'), 'private file');
    mkdir($siblingDirectory = storage_path('app/public-originals'), recursive: true);
    file_put_contents($siblingDirectory . '/photo.jpg', 'private file');

    $this->withoutExceptionHandling();

    foreach (['../photo.jpg', '../public-originals/photo.jpg'] as $path) {
        expect(fn () => pest()->get(tenant_asset($path), ['X-Tenant' => $tenant->id]))
            ->toThrow(Exception::class, 'Accessing a file outside the storage root'); // outside tests this is a 404
    }
});
