<?php

declare(strict_types=1);

use Illuminate\Support\Str;
use Illuminate\Mail\MailManager;
use Illuminate\Support\Facades\DB;
use Stancl\JobPipeline\JobPipeline;
use Illuminate\Support\Facades\File;
use Stancl\Tenancy\Bootstrappers\PrefixCacheTenancyBootstrapper;
use Stancl\Tenancy\Tests\Etc\Tenant;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Storage;
use Stancl\Tenancy\Events\TenancyEnded;
use Stancl\Tenancy\Jobs\CreateDatabase;
use Stancl\Tenancy\Events\TenantCreated;
use Stancl\Tenancy\Events\TenantDeleted;
use Stancl\Tenancy\Events\DeletingTenant;
use Illuminate\Filesystem\FilesystemAdapter;
use Stancl\Tenancy\Events\TenancyInitialized;
use Stancl\Tenancy\Jobs\CreateStorageSymlinks;
use Stancl\Tenancy\Jobs\RemoveStorageSymlinks;
use Stancl\Tenancy\Listeners\BootstrapTenancy;
use Stancl\Tenancy\Listeners\DeleteTenantStorage;
use Stancl\Tenancy\Listeners\RevertToCentralContext;
use Stancl\Tenancy\Bootstrappers\MailTenancyBootstrapper;
use Stancl\Tenancy\Bootstrappers\CacheTenancyBootstrapper;
use Stancl\Tenancy\Bootstrappers\RedisTenancyBootstrapper;
use Stancl\Tenancy\Bootstrappers\DatabaseTenancyBootstrapper;
use Stancl\Tenancy\Bootstrappers\FilesystemTenancyBootstrapper;
use Stancl\Tenancy\CacheManager;

beforeEach(function () {
    $this->mockConsoleOutput = false;

    Event::listen(
        TenantCreated::class,
        JobPipeline::make([CreateDatabase::class])->send(function (TenantCreated $event) {
            return $event->tenant;
        })->toListener()
    );

    Event::listen(TenancyInitialized::class, BootstrapTenancy::class);
    Event::listen(TenancyEnded::class, RevertToCentralContext::class);
});

test('database data is separated', function () {
    config(['tenancy.bootstrappers' => [
        DatabaseTenancyBootstrapper::class,
    ]]);

    $tenant1 = Tenant::create();
    $tenant2 = Tenant::create();

    pest()->artisan('tenants:migrate');

    tenancy()->initialize($tenant1);

    // Create Foo user
    DB::table('users')->insert(['name' => 'Foo', 'email' => 'foo@bar.com', 'password' => 'secret']);
    expect(DB::table('users')->get())->toHaveCount(1);

    tenancy()->initialize($tenant2);

    // Assert Foo user is not in this DB
    expect(DB::table('users')->get())->toHaveCount(0);
    // Create Bar user
    DB::table('users')->insert(['name' => 'Bar', 'email' => 'bar@bar.com', 'password' => 'secret']);
    expect(DB::table('users')->get())->toHaveCount(1);

    tenancy()->initialize($tenant1);

    // Assert Bar user is not in this DB
    expect(DB::table('users')->get())->toHaveCount(1);
    expect(DB::table('users')->first()->name)->toBe('Foo');
});

test('cache data is separated', function (string $bootstrapper) {
    CacheManager::$addTags = true;

    config([
        'tenancy.bootstrappers' => [$bootstrapper],
        'cache.default' => 'redis',
    ]);

    $tenant1 = Tenant::create();
    $tenant2 = Tenant::create();

    cache()->set('foo', 'central');
    expect(Cache::get('foo'))->toBe('central');

    tenancy()->initialize($tenant1);

    // Assert central cache doesn't leak to tenant context
    expect(Cache::has('foo'))->toBeFalse();

    cache()->set('foo', 'bar');
    expect(Cache::get('foo'))->toBe('bar');

    tenancy()->initialize($tenant2);

    // Assert one tenant's data doesn't leak to another tenant
    expect(Cache::has('foo'))->toBeFalse();

    cache()->set('foo', 'xyz');
    expect(Cache::get('foo'))->toBe('xyz');

    tenancy()->initialize($tenant1);

    // Asset data didn't leak to original tenant
    expect(Cache::get('foo'))->toBe('bar');

    tenancy()->end();

    // Asset central is still the same
    expect(Cache::get('foo'))->toBe('central');
})->with([
    'CacheTenancyBootstrapper' => CacheTenancyBootstrapper::class,
    'PrefixCacheTenancyBootstrapper' => PrefixCacheTenancyBootstrapper::class,
])->group('bootstrapper');

test('redis data is separated', function () {
    config(['tenancy.bootstrappers' => [
        RedisTenancyBootstrapper::class,
    ]]);

    $tenant1 = Tenant::create();
    $tenant2 = Tenant::create();

    tenancy()->initialize($tenant1);
    Redis::set('foo', 'bar');
    expect(Redis::get('foo'))->toBe('bar');

    tenancy()->initialize($tenant2);
    expect(Redis::get('foo'))->toBe(null);
    Redis::set('foo', 'xyz');
    Redis::set('abc', 'def');
    expect(Redis::get('foo'))->toBe('xyz');
    expect(Redis::get('abc'))->toBe('def');

    tenancy()->initialize($tenant1);
    expect(Redis::get('foo'))->toBe('bar');
    expect(Redis::get('abc'))->toBe(null);

    $tenant3 = Tenant::create();
    tenancy()->initialize($tenant3);
    expect(Redis::get('foo'))->toBe(null);
    expect(Redis::get('abc'))->toBe(null);
});

test('filesystem data is separated', function () {
    config(['tenancy.bootstrappers' => [
        FilesystemTenancyBootstrapper::class,
    ]]);

    $old_storage_path = storage_path();
    $old_storage_facade_roots = [];
    foreach (config('tenancy.filesystem.disks') as $disk) {
        $old_storage_facade_roots[$disk] = config("filesystems.disks.{$disk}.root");
    }

    $tenant1 = Tenant::create();
    $tenant2 = Tenant::create();

    tenancy()->initialize($tenant1);

    Storage::disk('public')->put('foo', 'bar');
    expect(Storage::disk('public')->get('foo'))->toBe('bar');

    tenancy()->initialize($tenant2);
    expect(Storage::disk('public')->exists('foo'))->toBeFalse();
    Storage::disk('public')->put('foo', 'xyz');
    Storage::disk('public')->put('abc', 'def');
    expect(Storage::disk('public')->get('foo'))->toBe('xyz');
    expect(Storage::disk('public')->get('abc'))->toBe('def');

    tenancy()->initialize($tenant1);
    expect(Storage::disk('public')->get('foo'))->toBe('bar');
    expect(Storage::disk('public')->exists('abc'))->toBeFalse();

    $tenant3 = Tenant::create();
    tenancy()->initialize($tenant3);
    expect(Storage::disk('public')->exists('foo'))->toBeFalse();
    expect(Storage::disk('public')->exists('abc'))->toBeFalse();

    $expected_storage_path = $old_storage_path . '/tenant' . tenant('id'); // /tenant = suffix base

    // Check that disk prefixes respect the root_override logic
    expect(getDiskPrefix('local'))->toBe($expected_storage_path . '/app/');
    expect(getDiskPrefix('public'))->toBe($expected_storage_path . '/app/public/');
    pest()->assertSame('tenant' . tenant('id') . '/', getDiskPrefix('s3'), '/');

    // Check suffixing logic
    $new_storage_path = storage_path();
    expect($new_storage_path)->toEqual($expected_storage_path);
});

test('tenant storage can get deleted after the tenant when DeletingTenant listens to DeleteTenantStorage', function () {
    config([
        'tenancy.bootstrappers' => [
            FilesystemTenancyBootstrapper::class,
        ],
        'tenancy.filesystem.root_override.public' => '%storage_path%/app/public/',
        'tenancy.filesystem.url_override.public' => 'public-%tenant_id%'
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
        'tenancy.filesystem.url_override.public' => 'public-%tenant_id%'
    ]);

    $tenant1 = Tenant::create();
    $tenant2 = Tenant::create();

    pest()->artisan('tenants:link');

    // First tenant
    tenancy()->initialize($tenant1);
    Storage::disk('public')->put($tenantFileName = 'tenant1.txt', $tenantKey = $tenant1->getTenantKey());

    $url = Storage::disk('public')->url($tenantFileName);
    $tenantDiskName = Str::of(config('tenancy.filesystem.url_override.public'))->replace('%tenant_id%', $tenantKey);
    $hostname = Str::of($url)->before($tenantDiskName);
    $parsedUrl = Str::of($url)->after($hostname);

    expect(file_get_contents(public_path($parsedUrl)))->toBe($tenantKey);

    // Second tenant
    tenancy()->initialize($tenant2);
    Storage::disk('public')->put($tenantFileName = 'tenant2.txt', $tenantKey = $tenant2->getTenantKey());

    $url = Storage::disk('public')->url($tenantFileName);
    $tenantDiskName = Str::of(config('tenancy.filesystem.url_override.public'))->replace('%tenant_id%', $tenantKey);
    $hostname = Str::of($url)->before($tenantDiskName);
    $parsedUrl = Str::of($url)->after($hostname);

    expect(file_get_contents(public_path($parsedUrl)))->toBe($tenantKey);
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
        'tenancy.filesystem.url_override.public' => 'public-%tenant_id%'
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

test('local storage public urls are generated correctly', function() {
    Event::listen(DeletingTenant::class, DeleteTenantStorage::class);

    tenancy()->initialize(Tenant::create());
    $tenantStoragePath = storage_path();

    Storage::fake('test');

    expect(File::isDirectory($tenantStoragePath))->toBeTrue();

    Storage::put('test.txt', 'testing file');

    tenant()->delete();

    expect(File::isDirectory($tenantStoragePath))->toBeFalse();
});

test('MailTenancyBootstrapper maps tenant mail credentials to config as specified in the $credentialsMap property and makes the mailer use tenant credentials', function() {
    MailTenancyBootstrapper::$credentialsMap = [
        'mail.mailers.smtp.username' => 'smtp_username',
        'mail.mailers.smtp.password' => 'smtp_password'
    ];

    config([
        'mail.default' => 'smtp',
        'mail.mailers.smtp.username' => $defaultUsername = 'default username',
        'mail.mailers.smtp.password' => 'no password'
    ]);

    $tenant = Tenant::create(['smtp_password' => $password = 'testing password']);

    tenancy()->initialize($tenant);

    expect(array_key_exists('smtp_password', tenant()->getAttributes()))->toBeTrue();
    expect(array_key_exists('smtp_host', tenant()->getAttributes()))->toBeFalse();
    expect(config('mail.mailers.smtp.username'))->toBe($defaultUsername);
    expect(config('mail.mailers.smtp.password'))->toBe(tenant()->smtp_password);

    // Assert that the current mailer uses tenant's smtp_password
    assertMailerTransportUsesPassword($password);
});

test('MailTenancyBootstrapper reverts the config and mailer credentials to default when tenancy ends', function() {
    MailTenancyBootstrapper::$credentialsMap = ['mail.mailers.smtp.password' => 'smtp_password'];
    config(['mail.default' => 'smtp', 'mail.mailers.smtp.password' => $defaultPassword = 'no password']);

    tenancy()->initialize(Tenant::create(['smtp_password' => $tenantPassword = 'testing password']));

    expect(config('mail.mailers.smtp.password'))->toBe($tenantPassword);

    assertMailerTransportUsesPassword($tenantPassword);

    tenancy()->end();

    expect(config('mail.mailers.smtp.password'))->toBe($defaultPassword);

    // Assert that the current mailer uses the default SMTP password
    assertMailerTransportUsesPassword($defaultPassword);
});

function getDiskPrefix(string $disk): string
{
    /** @var FilesystemAdapter $disk */
    $disk = Storage::disk($disk);
    $adapter = $disk->getAdapter();
    $prefix = invade(invade($adapter)->prefixer)->prefix;

    return $prefix;
}
