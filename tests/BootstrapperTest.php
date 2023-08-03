<?php

declare(strict_types=1);

use Illuminate\Support\Str;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\URL;
use Stancl\JobPipeline\JobPipeline;
use Illuminate\Support\Facades\File;
use Stancl\Tenancy\Tests\Etc\Tenant;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Stancl\Tenancy\Events\TenancyEnded;
use Stancl\Tenancy\Jobs\CreateDatabase;
use Stancl\Tenancy\Overrides\TenancyUrlGenerator;
use Stancl\Tenancy\Events\TenantCreated;
use Stancl\Tenancy\Events\TenantDeleted;
use Stancl\Tenancy\Events\DeletingTenant;
use Stancl\Tenancy\Overrides\TenancyBroadcastManager;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Broadcasting\BroadcastManager;
use Stancl\Tenancy\Events\TenancyInitialized;
use Illuminate\Contracts\Routing\UrlGenerator;
use Stancl\Tenancy\Jobs\CreateStorageSymlinks;
use Stancl\Tenancy\Jobs\RemoveStorageSymlinks;
use Stancl\Tenancy\Listeners\BootstrapTenancy;
use Stancl\Tenancy\Tests\Etc\TestingBroadcaster;
use Stancl\Tenancy\Listeners\DeleteTenantStorage;
use Stancl\Tenancy\Listeners\RevertToCentralContext;
use Stancl\Tenancy\Bootstrappers\CacheTagsBootstrapper;
use Stancl\Tenancy\Bootstrappers\UrlTenancyBootstrapper;
use Stancl\Tenancy\Bootstrappers\MailTenancyBootstrapper;
use Stancl\Tenancy\Bootstrappers\UrlBindingBootstrapper;
use Stancl\Tenancy\Bootstrappers\RedisTenancyBootstrapper;
use Stancl\Tenancy\Middleware\InitializeTenancyBySubdomain;
use Stancl\Tenancy\Bootstrappers\DatabaseTenancyBootstrapper;
use Stancl\Tenancy\Bootstrappers\BroadcastTenancyBootstrapper;
use Stancl\Tenancy\Bootstrappers\FilesystemTenancyBootstrapper;
use Stancl\Tenancy\Bootstrappers\PrefixCacheTenancyBootstrapper;
use Stancl\Tenancy\Bootstrappers\Integrations\FortifyRouteTenancyBootstrapper;
use Stancl\Tenancy\Middleware\InitializeTenancyByPath;
use Stancl\Tenancy\Middleware\InitializeTenancyByRequestData;

beforeEach(function () {
    $this->mockConsoleOutput = false;

    config(['cache.default' => $cacheDriver = 'redis']);
    PrefixCacheTenancyBootstrapper::$tenantCacheStores = [$cacheDriver];
    // Reset static properties of classes used in this test file to their default values
    BroadcastTenancyBootstrapper::$credentialsMap = [];
    TenancyBroadcastManager::$tenantBroadcasters = ['pusher', 'ably'];
    UrlTenancyBootstrapper::$rootUrlOverride = null;

    Event::listen(
        TenantCreated::class,
        JobPipeline::make([CreateDatabase::class])->send(function (TenantCreated $event) {
            return $event->tenant;
        })->toListener()
    );

    Event::listen(TenancyInitialized::class, BootstrapTenancy::class);
    Event::listen(TenancyEnded::class, RevertToCentralContext::class);
});

afterEach(function () {
    // Reset static properties of classes used in this test file to their default values
    UrlTenancyBootstrapper::$rootUrlOverride = null;
    PrefixCacheTenancyBootstrapper::$tenantCacheStores = [];
    TenancyBroadcastManager::$tenantBroadcasters = ['pusher', 'ably'];
    BroadcastTenancyBootstrapper::$credentialsMap = [];
    TenancyUrlGenerator::$prefixRouteNames = false;
});

test('database data is separated', function () {
    config(['tenancy.bootstrappers' => [DatabaseTenancyBootstrapper::class]]);

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
    config([
        'tenancy.bootstrappers' => [$bootstrapper],
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
    CacheTagsBootstrapper::class,
    PrefixCacheTenancyBootstrapper::class,
]);

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

test('BroadcastTenancyBootstrapper binds TenancyBroadcastManager to BroadcastManager and reverts the binding when tenancy is ended', function() {
    config(['tenancy.bootstrappers' => [BroadcastTenancyBootstrapper::class]]);

    expect(app(BroadcastManager::class))->toBeInstanceOf(BroadcastManager::class);

    tenancy()->initialize(Tenant::create());

    expect(app(BroadcastManager::class))->toBeInstanceOf(TenancyBroadcastManager::class);

    tenancy()->end();

    expect(app(BroadcastManager::class))->toBeInstanceOf(BroadcastManager::class);
});

test('BroadcastTenancyBootstrapper maps tenant broadcaster credentials to config as specified in the $credentialsMap property and reverts the config after ending tenancy', function() {
    config([
        'broadcasting.connections.testing.driver' => 'testing',
        'broadcasting.connections.testing.message' => $defaultMessage = 'default',
        'tenancy.bootstrappers' => [BroadcastTenancyBootstrapper::class],
    ]);

    BroadcastTenancyBootstrapper::$credentialsMap = [
        'broadcasting.connections.testing.message' => 'testing_broadcaster_message',
    ];

    $tenant = Tenant::create(['testing_broadcaster_message' => $tenantMessage = 'first testing']);
    $tenant2 = Tenant::create(['testing_broadcaster_message' => $secondTenantMessage = 'second testing']);

    tenancy()->initialize($tenant);

    expect(array_key_exists('testing_broadcaster_message', tenant()->getAttributes()))->toBeTrue();
    expect(config('broadcasting.connections.testing.message'))->toBe($tenantMessage);

    tenancy()->initialize($tenant2);

    expect(config('broadcasting.connections.testing.message'))->toBe($secondTenantMessage);

    tenancy()->end();

    expect(config('broadcasting.connections.testing.message'))->toBe($defaultMessage);
});

test('BroadcastTenancyBootstrapper makes the app use broadcasters with the correct credentials', function() {
    config([
        'broadcasting.default' => 'testing',
        'broadcasting.connections.testing.driver' => 'testing',
        'broadcasting.connections.testing.message' => $defaultMessage = 'default',
        'tenancy.bootstrappers' => [BroadcastTenancyBootstrapper::class],
    ]);

    TenancyBroadcastManager::$tenantBroadcasters[] = 'testing';
    BroadcastTenancyBootstrapper::$credentialsMap = [
        'broadcasting.connections.testing.message' => 'testing_broadcaster_message',
    ];

    $registerTestingBroadcaster = fn() => app(BroadcastManager::class)->extend('testing', fn($app, $config) => new TestingBroadcaster($config['message']));

    $registerTestingBroadcaster();

    expect(invade(app(BroadcastManager::class)->driver())->message)->toBe($defaultMessage);

    $tenant = Tenant::create(['testing_broadcaster_message' => $tenantMessage = 'first testing']);
    $tenant2 = Tenant::create(['testing_broadcaster_message' => $secondTenantMessage = 'second testing']);

    tenancy()->initialize($tenant);
    $registerTestingBroadcaster();

    expect(invade(app(BroadcastManager::class)->driver())->message)->toBe($tenantMessage);

    tenancy()->initialize($tenant2);
    $registerTestingBroadcaster();

    expect(invade(app(BroadcastManager::class)->driver())->message)->toBe($secondTenantMessage);

    tenancy()->end();
    $registerTestingBroadcaster();

    expect(invade(app(BroadcastManager::class)->driver())->message)->toBe($defaultMessage);
});

test('MailTenancyBootstrapper maps tenant mail credentials to config as specified in the $credentialsMap property and makes the mailer use tenant credentials', function() {
    MailTenancyBootstrapper::$credentialsMap = [
        'mail.mailers.smtp.username' => 'smtp_username',
        'mail.mailers.smtp.password' => 'smtp_password'
    ];

    config([
        'mail.default' => 'smtp',
        'mail.mailers.smtp.username' => $defaultUsername = 'default username',
        'mail.mailers.smtp.password' => 'no password',
        'tenancy.bootstrappers' => [MailTenancyBootstrapper::class],
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
    config([
        'mail.default' => 'smtp',
        'mail.mailers.smtp.password' => $defaultPassword = 'no password',
        'tenancy.bootstrappers' => [MailTenancyBootstrapper::class],
    ]);

    tenancy()->initialize(Tenant::create(['smtp_password' => $tenantPassword = 'testing password']));

    expect(config('mail.mailers.smtp.password'))->toBe($tenantPassword);

    assertMailerTransportUsesPassword($tenantPassword);

    tenancy()->end();

    expect(config('mail.mailers.smtp.password'))->toBe($defaultPassword);

    // Assert that the current mailer uses the default SMTP password
    assertMailerTransportUsesPassword($defaultPassword);
});

test('url bootstrapper overrides the root url when tenancy gets initialized and reverts the url to the central one after tenancy ends', function() {
    config(['tenancy.bootstrappers' => [UrlTenancyBootstrapper::class]]);

    Route::group([
        'middleware' => InitializeTenancyBySubdomain::class,
    ], function () {
        Route::get('/', function () {
            return true;
        })->name('home');
    });

    $baseUrl = url(route('home'));
    config(['app.url' => $baseUrl]);

    $rootUrlOverride = function (Tenant $tenant) use ($baseUrl) {
        $scheme = str($baseUrl)->before('://');
        $hostname = str($baseUrl)->after($scheme . '://');

        return $scheme . '://' . $tenant->getTenantKey() . '.' . $hostname;
    };

    UrlTenancyBootstrapper::$rootUrlOverride = $rootUrlOverride;

    $tenant = Tenant::create();
    $tenantUrl = $rootUrlOverride($tenant);

    expect($tenantUrl)->not()->toBe($baseUrl);

    expect(url(route('home')))->toBe($baseUrl);
    expect(URL::to('/'))->toBe($baseUrl);
    expect(config('app.url'))->toBe($baseUrl);

    tenancy()->initialize($tenant);

    expect(url(route('home')))->toBe($tenantUrl);
    expect(URL::to('/'))->toBe($tenantUrl);
    expect(config('app.url'))->toBe($tenantUrl);

    tenancy()->end();

    expect(url(route('home')))->toBe($baseUrl);
    expect(URL::to('/'))->toBe($baseUrl);
    expect(config('app.url'))->toBe($baseUrl);
});

test('url binding tenancy bootstrapper swaps the url generator instance correctly', function() {
    config(['tenancy.bootstrappers' => [UrlBindingBootstrapper::class]]);

    tenancy()->initialize(Tenant::create());
    expect(app('url'))->toBeInstanceOf(TenancyUrlGenerator::class);
    expect(url())->toBeInstanceOf(TenancyUrlGenerator::class);

    tenancy()->end();
    expect(app('url'))->toBeInstanceOf(UrlGenerator::class);
    expect(url())->toBeInstanceOf(UrlGenerator::class);
});

test('url binding tenancy bootstrapper changes route helper behavior correctly', function() {
    Route::get('/central/home', fn () => route('home'))->name('home');
    // Tenant route name prefix is 'tenant.' by default
    Route::get('/{tenant}/home', fn () => route('tenant.home'))->name('tenant.home')->middleware(['tenant', InitializeTenancyByPath::class]);
    Route::get('/query-string', fn () => route('query-string'))->name('query-string')->middleware(['tenant', InitializeTenancyByRequestData::class]);

    $tenant = Tenant::create();
    $tenantKey = $tenant->getTenantKey();
    $centralRouteUrl = route('home');
    $tenantRouteUrl = route('tenant.home', ['tenant' => $tenantKey]);
    $queryStringCentralUrl = route('query-string');
    $queryStringTenantUrl = route('query-string', ['tenant' => $tenantKey]);
    TenancyUrlGenerator::$bypassParameter = 'bypassParameter';
    $bypassParameter = TenancyUrlGenerator::$bypassParameter;

    config(['tenancy.bootstrappers' => [UrlBindingBootstrapper::class]]);
    TenancyUrlGenerator::$prefixRouteNames = true;

    tenancy()->initialize($tenant);
    // The $prefixRouteNames property is true
    // The route name passed to the route() helper ('home') gets prefixed prefixed with 'tenant.' automatically
    expect(route('home'))->toBe($tenantRouteUrl);

    // The 'tenant.home' route name doesn't get prefixed because it is already prefixed with 'tenant.'
    // Also, the route receives the tenant parameter automatically
    expect(route('tenant.home'))->toBe($tenantRouteUrl);

    // The $bypassParameter parameter ('central' by default) can bypass the route name prefixing
    // When the bypass parameter is true, the generated route URL points to the route named 'home'
    // Also, check if the bypass parameter gets removed from the generated URL query string
    expect(route('home', [$bypassParameter => true]))->toBe($centralRouteUrl)
        ->not()->toContain($bypassParameter);
    // When the bypass parameter is false, the generated route URL points to the prefixed route ('tenant.home')
    expect(route('home', [$bypassParameter => false]))->toBe($tenantRouteUrl)
        ->not()->toContain($bypassParameter);

    TenancyUrlGenerator::$prefixRouteNames = false;
    // Route names don't get prefixed – TenancyUrlGenerator::$prefixRouteNames is false
    expect(route('home', [$bypassParameter => true]))->toBe($centralRouteUrl);
    expect(route('query-string'))->toBe($queryStringTenantUrl);

    TenancyUrlGenerator::$passTenantParameterToRoutes = false;
    expect(route('query-string'))->toBe($queryStringCentralUrl);

    TenancyUrlGenerator::$passTenantParameterToRoutes = true;
    expect(route('query-string'))->toBe($queryStringTenantUrl);

    // Ending tenancy reverts route() behavior changes
    tenancy()->end();

    expect(route('home'))->toBe($centralRouteUrl);
    expect(route('query-string'))->toBe($queryStringCentralUrl);
    expect(route('tenant.home', ['tenant' => $tenantKey]))->toBe($tenantRouteUrl);

    // Route-level identification
    pest()->get("http://localhost/central/home")->assertSee($centralRouteUrl);
    pest()->get("http://localhost/$tenantKey/home")->assertSee($tenantRouteUrl);
    pest()->get("http://localhost/query-string?tenant=$tenantKey")->assertSee($queryStringTenantUrl);
})->group('string');

test('fortify route tenancy bootstrapper updates fortify config correctly', function() {
    config(['tenancy.bootstrappers' => [FortifyRouteTenancyBootstrapper::class]]);

    Route::get('/', function () {
        return true;
    })->name($tenantHomeRouteName = 'tenant.home');

    FortifyRouteTenancyBootstrapper::$fortifyHome = $tenantHomeRouteName;
    FortifyRouteTenancyBootstrapper::$fortifyRedirectTenantMap = ['logout' => FortifyRouteTenancyBootstrapper::$fortifyHome];
    $originalFortifyHome = config('fortify.home');
    $originalFortifyRedirects = config('fortify.redirects');

    tenancy()->initialize(Tenant::create());
    expect(config('fortify.home'))->toBe($homeUrl = route($tenantHomeRouteName));
    expect(config('fortify.redirects'))->toBe(['logout' => $homeUrl]);

    tenancy()->end();
    expect(config('fortify.home'))->toBe($originalFortifyHome);
    expect(config('fortify.redirects'))->toBe($originalFortifyRedirects);
});

function getDiskPrefix(string $disk): string
{
    /** @var FilesystemAdapter $disk */
    $disk = Storage::disk($disk);
    $adapter = $disk->getAdapter();
    $prefix = invade(invade($adapter)->prefixer)->prefix;

    return $prefix;
}
