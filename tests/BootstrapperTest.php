<?php

declare(strict_types=1);

use Illuminate\Support\Str;
use Stancl\Tenancy\Enums\Context;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\URL;
use Stancl\JobPipeline\JobPipeline;
use Illuminate\Support\Facades\File;
use Stancl\Tenancy\Tests\Etc\Tenant;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Stancl\Tenancy\Events\TenancyEnded;
use Stancl\Tenancy\Jobs\CreateDatabase;
use Stancl\Tenancy\Events\TenantCreated;
use Stancl\Tenancy\Events\TenantDeleted;
use Illuminate\Database\Schema\Blueprint;
use Stancl\Tenancy\Events\DeletingTenant;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Broadcasting\BroadcastManager;
use Stancl\Tenancy\Events\TenancyInitialized;
use Illuminate\Contracts\Routing\UrlGenerator;
use Stancl\Tenancy\Jobs\CreateStorageSymlinks;
use Stancl\Tenancy\Jobs\RemoveStorageSymlinks;
use Stancl\Tenancy\Listeners\BootstrapTenancy;
use Stancl\Tenancy\Resolvers\PathTenantResolver;
use Stancl\Tenancy\Tests\Etc\TestingBroadcaster;
use Stancl\Tenancy\Listeners\DeleteTenantStorage;
use Stancl\Tenancy\Overrides\TenancyUrlGenerator;
use Stancl\Tenancy\Listeners\RevertToCentralContext;
use Stancl\Tenancy\Bootstrappers\RootUrlBootstrapper;
use Stancl\Tenancy\Overrides\TenancyBroadcastManager;
use Stancl\Tenancy\Middleware\InitializeTenancyByPath;
use Stancl\Tenancy\Bootstrappers\CacheTagsBootstrapper;
use Illuminate\Routing\Exceptions\UrlGenerationException;
use Stancl\Tenancy\Bootstrappers\MailTenancyBootstrapper;
use Stancl\Tenancy\Bootstrappers\RedisTenancyBootstrapper;
use Stancl\Tenancy\Bootstrappers\UrlGeneratorBootstrapper;
use Stancl\Tenancy\Middleware\InitializeTenancyBySubdomain;
use Stancl\Tenancy\Bootstrappers\DatabaseTenancyBootstrapper;
use Stancl\Tenancy\Middleware\InitializeTenancyByRequestData;
use Stancl\Tenancy\Bootstrappers\FilesystemTenancyBootstrapper;
use Stancl\Tenancy\Bootstrappers\BroadcastingConfigBootstrapper;
use Stancl\Tenancy\Bootstrappers\PrefixCacheTenancyBootstrapper;
use Stancl\Tenancy\Bootstrappers\BroadcastChannelPrefixBootstrapper;
use Stancl\Tenancy\Bootstrappers\Integrations\FortifyRouteTenancyBootstrapper;

beforeEach(function () {
    $this->mockConsoleOutput = false;

    config(['cache.default' => $cacheDriver = 'redis']);
    PrefixCacheTenancyBootstrapper::$tenantCacheStores = [$cacheDriver];
    // Reset static properties of classes used in this test file to their default values
    BroadcastingConfigBootstrapper::$credentialsMap = [];
    TenancyBroadcastManager::$tenantBroadcasters = ['pusher', 'ably'];
    RootUrlBootstrapper::$rootUrlOverride = null;

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
    RootUrlBootstrapper::$rootUrlOverride = null;
    PrefixCacheTenancyBootstrapper::$tenantCacheStores = [];
    TenancyBroadcastManager::$tenantBroadcasters = ['pusher', 'ably'];
    BroadcastingConfigBootstrapper::$credentialsMap = [];
    TenancyUrlGenerator::$prefixRouteNames = false;
    TenancyUrlGenerator::$passTenantParameterToRoutes = true;
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

    // Central
    tenancy()->end();
    Storage::disk('public')->put($centralFileName = 'central.txt', $centralFileContent = 'central');

    pest()->artisan('storage:link');
    $url = Storage::disk('public')->url($centralFileName);

    expect(file_get_contents(public_path($url)))->toBe($centralFileContent);
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

test('BroadcastingConfigBootstrapper binds TenancyBroadcastManager to BroadcastManager and reverts the binding when tenancy is ended', function() {
    config(['tenancy.bootstrappers' => [BroadcastingConfigBootstrapper::class]]);

    expect(app(BroadcastManager::class))->toBeInstanceOf(BroadcastManager::class);

    tenancy()->initialize(Tenant::create());

    expect(app(BroadcastManager::class))->toBeInstanceOf(TenancyBroadcastManager::class);

    tenancy()->end();

    expect(app(BroadcastManager::class))->toBeInstanceOf(BroadcastManager::class);
});

test('BroadcastingConfigBootstrapper maps tenant broadcaster credentials to config as specified in the $credentialsMap property and reverts the config after ending tenancy', function() {
    config([
        'broadcasting.connections.testing.driver' => 'testing',
        'broadcasting.connections.testing.message' => $defaultMessage = 'default',
        'tenancy.bootstrappers' => [BroadcastingConfigBootstrapper::class],
    ]);

    BroadcastingConfigBootstrapper::$credentialsMap = [
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

test('BroadcastingConfigBootstrapper makes the app use broadcasters with the correct credentials', function() {
    config([
        'broadcasting.default' => 'testing',
        'broadcasting.connections.testing.driver' => 'testing',
        'broadcasting.connections.testing.message' => $defaultMessage = 'default',
        'tenancy.bootstrappers' => [BroadcastingConfigBootstrapper::class],
    ]);

    TenancyBroadcastManager::$tenantBroadcasters[] = 'testing';
    BroadcastingConfigBootstrapper::$credentialsMap = [
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
    config(['tenancy.bootstrappers' => [RootUrlBootstrapper::class]]);

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

    RootUrlBootstrapper::$rootUrlOverride = $rootUrlOverride;

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
    config(['tenancy.bootstrappers' => [UrlGeneratorBootstrapper::class]]);

    tenancy()->initialize(Tenant::create());
    expect(app('url'))->toBeInstanceOf(TenancyUrlGenerator::class);
    expect(url())->toBeInstanceOf(TenancyUrlGenerator::class);

    tenancy()->end();
    expect(app('url'))->toBeInstanceOf(UrlGenerator::class)
        ->not()->toBeInstanceOf(TenancyUrlGenerator::class);
    expect(url())->toBeInstanceOf(UrlGenerator::class)
        ->not()->toBeInstanceOf(TenancyUrlGenerator::class);
});

test('url generator bootstrapper can prefix route names passed to the route helper', function() {
    Route::get('/central/home', fn () => route('home'))->name('home');
    // Tenant route name prefix is 'tenant.' by default
    Route::get('/{tenant}/home', fn () => route('tenant.home'))->name('tenant.home')->middleware(['tenant', InitializeTenancyByPath::class]);

    $tenant = Tenant::create();
    $tenantKey = $tenant->getTenantKey();
    $centralRouteUrl = route('home');
    $tenantRouteUrl = route('tenant.home', ['tenant' => $tenantKey]);
    TenancyUrlGenerator::$bypassParameter = 'bypassParameter';

    config(['tenancy.bootstrappers' => [UrlGeneratorBootstrapper::class]]);

    tenancy()->initialize($tenant);

    // Route names don't get prefixed when TenancyUrlGenerator::$prefixRouteNames is false
    expect(route('home'))->not()->toBe($centralRouteUrl);
    // When TenancyUrlGenerator::$passTenantParameterToRoutes is true (default)
    // The route helper receives the tenant parameter
    // So in order to generate central URL, we have to pass the bypass parameter
    expect(route('home', ['bypassParameter' => true]))->toBe($centralRouteUrl);


    TenancyUrlGenerator::$prefixRouteNames = true;
    // The $prefixRouteNames property is true
    // The route name passed to the route() helper ('home') gets prefixed prefixed with 'tenant.' automatically
    expect(route('home'))->toBe($tenantRouteUrl);

    // The 'tenant.home' route name doesn't get prefixed because it is already prefixed with 'tenant.'
    // Also, the route receives the tenant parameter automatically
    expect(route('tenant.home'))->toBe($tenantRouteUrl);

    // Ending tenancy reverts route() behavior changes
    tenancy()->end();

    expect(route('home'))->toBe($centralRouteUrl);
});

test('both the name prefixing and the tenant parameter logic gets skipped when bypass parameter is used', function () {
    $tenantParameterName = PathTenantResolver::tenantParameterName();

    Route::get('/central/home', fn () => route('home'))->name('home');
    // Tenant route name prefix is 'tenant.' by default
    Route::get('/{tenant}/home', fn () => route('tenant.home'))->name('tenant.home')->middleware(['tenant', InitializeTenancyByPath::class]);

    $tenant = Tenant::create();
    $centralRouteUrl = route('home');
    $tenantRouteUrl = route('tenant.home', ['tenant' => $tenant->getTenantKey()]);
    config(['tenancy.bootstrappers' => [UrlGeneratorBootstrapper::class]]);

    TenancyUrlGenerator::$prefixRouteNames = true;
    TenancyUrlGenerator::$bypassParameter = 'bypassParameter';

    tenancy()->initialize($tenant);

    // The $bypassParameter parameter ('central' by default) can bypass the route name prefixing
    // When the bypass parameter is true, the generated route URL points to the route named 'home'
    expect(route('home', ['bypassParameter' => true]))->toBe($centralRouteUrl)
        // Bypass parameter prevents passing the tenant parameter directly
        ->not()->toContain($tenantParameterName . '=')
        // Bypass parameter gets removed from the generated URL automatically
        ->not()->toContain('bypassParameter');

    // When the bypass parameter is false, the generated route URL points to the prefixed route ('tenant.home')
    expect(route('home', ['bypassParameter' => false]))->toBe($tenantRouteUrl)
        ->not()->toContain('bypassParameter');
});

test('url generator bootstrapper can make route helper generate links with the tenant parameter', function() {
    Route::get('/query_string', fn () => route('query_string'))->name('query_string')->middleware(['universal', InitializeTenancyByRequestData::class]);
    Route::get('/path', fn () => route('path'))->name('path');
    Route::get('/{tenant}/path', fn () => route('tenant.path'))->name('tenant.path')->middleware([InitializeTenancyByPath::class]);

    $tenant = Tenant::create();
    $tenantKey = $tenant->getTenantKey();
    $queryStringCentralUrl = route('query_string');
    $queryStringTenantUrl = route('query_string', ['tenant' => $tenantKey]);
    $pathCentralUrl = route('path');
    $pathTenantUrl = route('tenant.path', ['tenant' => $tenantKey]);

    // Makes the route helper receive the tenant parameter whenever available
    // Unless the bypass parameter is true
    TenancyUrlGenerator::$passTenantParameterToRoutes = true;

    TenancyUrlGenerator::$bypassParameter = 'bypassParameter';

    config(['tenancy.bootstrappers' => [UrlGeneratorBootstrapper::class]]);

    expect(route('path'))->toBe($pathCentralUrl);
    // Tenant parameter required, but not passed since tenancy wasn't initialized
    expect(fn () => route('tenant.path'))->toThrow(UrlGenerationException::class);

    tenancy()->initialize($tenant);

    // Tenant parameter is passed automatically
    expect(route('path'))->not()->toBe($pathCentralUrl); // Parameter added as query string – bypassParameter needed
    expect(route('path', ['bypassParameter' => true]))->toBe($pathCentralUrl);
    expect(route('tenant.path'))->toBe($pathTenantUrl);

    expect(route('query_string'))->toBe($queryStringTenantUrl)->toContain('tenant=');
    expect(route('query_string', ['bypassParameter' => 'true']))->toBe($queryStringCentralUrl)->not()->toContain('tenant=');

    tenancy()->end();

    expect(route('query_string'))->toBe($queryStringCentralUrl);

    // Tenant parameter required, but shouldn't be passed since tenancy isn't initialized
    expect(fn () => route('tenant.path'))->toThrow(UrlGenerationException::class);

    // Route-level identification
    pest()->get("http://localhost/query_string")->assertSee($queryStringCentralUrl);
    pest()->get("http://localhost/query_string?tenant=$tenantKey")->assertSee($queryStringTenantUrl);
    pest()->get("http://localhost/path")->assertSee($pathCentralUrl);
    pest()->get("http://localhost/$tenantKey/path")->assertSee($pathTenantUrl);
});

test('fortify route tenancy bootstrapper updates fortify config correctly', function() {
    config(['tenancy.bootstrappers' => [FortifyRouteTenancyBootstrapper::class]]);

    $originalFortifyHome = config('fortify.home');
    $originalFortifyRedirects = config('fortify.redirects');

    Route::get('/home', function () {
        return true;
    })->name($homeRouteName = 'home');

    Route::get('/{tenant}/home', function () {
        return true;
    })->name($pathIdHomeRouteName = 'tenant.home');

    Route::get('/welcome', function () {
        return true;
    })->name($welcomeRouteName = 'welcome');

    Route::get('/{tenant}/welcome', function () {
        return true;
    })->name($pathIdWelcomeRouteName = 'path.welcome');

    FortifyRouteTenancyBootstrapper::$fortifyHome = $homeRouteName;

    // Make login redirect to the central welcome route
    FortifyRouteTenancyBootstrapper::$fortifyRedirectMap['login'] = [
        'route_name' => $welcomeRouteName,
        'context' => Context::CENTRAL,
    ];

    tenancy()->initialize($tenant = Tenant::create());
    // The bootstraper makes fortify.home always receive the tenant parameter
    expect(config('fortify.home'))->toBe('http://localhost/home?tenant=' . $tenant->getTenantKey());

    // The login redirect route has the central context specified, so it doesn't receive the tenant parameter
    expect(config('fortify.redirects'))->toEqual(['login' => 'http://localhost/welcome']);

    tenancy()->end();
    expect(config('fortify.home'))->toBe($originalFortifyHome);
    expect(config('fortify.redirects'))->toBe($originalFortifyRedirects);

    // Making a route's context will pass the tenant parameter to the route
    FortifyRouteTenancyBootstrapper::$fortifyRedirectMap['login']['context'] = Context::TENANT;

    tenancy()->initialize($tenant);

    expect(config('fortify.redirects'))->toEqual(['login' => 'http://localhost/welcome?tenant=' . $tenant->getTenantKey()]);

    // Make the home and login route accept the tenant as a route parameter
    // To confirm that tenant route parameter gets filled automatically too (path identification works as well as query string)
    FortifyRouteTenancyBootstrapper::$fortifyHome = $pathIdHomeRouteName;
    FortifyRouteTenancyBootstrapper::$fortifyRedirectMap['login']['route_name'] = $pathIdWelcomeRouteName;

    tenancy()->end();

    tenancy()->initialize($tenant);

    expect(config('fortify.home'))->toBe("http://localhost/{$tenant->getTenantKey()}/home");
    expect(config('fortify.redirects'))->toEqual(['login' => "http://localhost/{$tenant->getTenantKey()}/welcome"]);
});

test('database tenancy bootstrapper throws an exception if DATABASE_URL is set', function (string|null $databaseUrl) {
    if ($databaseUrl) {
        config(['database.connections.central.url' => $databaseUrl]);

        pest()->expectException(Exception::class);
    }

    config(['tenancy.bootstrappers' => [DatabaseTenancyBootstrapper::class]]);

    $tenant1 = Tenant::create();

    pest()->artisan('tenants:migrate');

    tenancy()->initialize($tenant1);

    expect(true)->toBe(true);
})->with(['abc.us-east-1.rds.amazonaws.com', null]);

test('BroadcastChannelPrefixBootstrapper prefixes the channels events are broadcast on while tenancy is initialized', function() {
    config([
        'broadcasting.default' => $driver = 'testing',
        'broadcasting.connections.testing.driver' => $driver,
    ]);

    // Use custom broadcaster
    app(BroadcastManager::class)->extend($driver, fn () => new TestingBroadcaster('original broadcaster'));

    config(['tenancy.bootstrappers' => [BroadcastChannelPrefixBootstrapper::class, DatabaseTenancyBootstrapper::class]]);

    Schema::create('users', function (Blueprint $table) {
        $table->increments('id');
        $table->string('name');
        $table->string('email')->unique();
        $table->string('password');
        $table->rememberToken();
        $table->timestamps();
    });

    universal_channel('users.{userId}', function ($user, $userId) {
        return User::find($userId)->is($user);
    });

    $broadcaster = app(BroadcastManager::class)->driver();

    $tenant = Tenant::create();
    $tenant2 = Tenant::create();

    pest()->artisan('tenants:migrate');

    // Set up the 'testing' broadcaster override
    // Identical to the default Pusher override (BroadcastChannelPrefixBootstrapper::pusher())
    // Except for the parent class (TestingBroadcaster instead of PusherBroadcaster)
    BroadcastChannelPrefixBootstrapper::$broadcasterOverrides['testing'] = function (BroadcastManager $broadcastManager) {
         $broadcastManager->extend('testing', function ($app, $config) {
            return new class('tenant broadcaster') extends TestingBroadcaster {
                protected function formatChannels(array $channels)
                    {
                        $formatChannel = function (string $channel) {
                            $prefixes = ['private-', 'presence-'];
                            $defaultPrefix = '';

                            foreach ($prefixes as $prefix) {
                                if (str($channel)->startsWith($prefix)) {
                                    $defaultPrefix = $prefix;
                                    break;
                                }
                            }

                            // Skip prefixing channels flagged with the global channel prefix
                            if (! str($channel)->startsWith('global__')) {
                                $channel = str($channel)->after($defaultPrefix)->prepend($defaultPrefix . tenant()->getTenantKey() . '.');
                            }

                            return (string) $channel;
                        };

                        return array_map($formatChannel, parent::formatChannels($channels));
                    }
            };
        });
    };

    auth()->login($user = User::create(['name' => 'central', 'email' => 'test@central.cz', 'password' => 'test']));

    // The channel names used for testing the formatChannels() method (not real channels)
    $channelNames = [
        'channel',
        'global__channel', // Channels prefixed with 'global__' shouldn't get prefixed with the tenant key
        'private-user.' . $user->id,
    ];

    // formatChannels doesn't prefix the channel names until tenancy is initialized
    expect(invade(app(BroadcastManager::class)->driver())->formatChannels($channelNames))->toEqual($channelNames);

    tenancy()->initialize($tenant);

    $tenantBroadcaster = app(BroadcastManager::class)->driver();

    auth()->login($tenantUser = User::create(['name' => 'tenant', 'email' => 'test@tenant.cz', 'password' => 'test']));

    // The current (tenant) broadcaster isn't the same as the central one
    expect($tenantBroadcaster->message)->not()->toBe($broadcaster->message);
    // Tenant broadcaster has the same channels as the central broadcaster
    expect($tenantBroadcaster->getChannels())->toEqualCanonicalizing($broadcaster->getChannels());
    // formatChannels prefixes the channel names now
    expect(invade($tenantBroadcaster)->formatChannels($channelNames))->toEqualCanonicalizing([
        'global__channel',
        $tenant->getTenantKey() . '.channel',
        'private-' . $tenant->getTenantKey() . '.user.' . $tenantUser->id,
    ]);

    // Initialize another tenant
    tenancy()->initialize($tenant2);

    auth()->login($tenantUser = User::create(['name' => 'tenant', 'email' => 'test2@tenant.cz', 'password' => 'test']));

    // formatChannels prefixes channels with the second tenant's key now
    expect(invade(app(BroadcastManager::class)->driver())->formatChannels($channelNames))->toEqualCanonicalizing([
        'global__channel',
        $tenant2->getTenantKey() . '.channel',
        'private-' . $tenant2->getTenantKey() . '.user.' . $tenantUser->id,
    ]);

    // The bootstrapper reverts to the tenant context – the channel names won't be prefixed anymore
    tenancy()->end();

    // The current broadcaster is the same as the central one again
    expect(app(BroadcastManager::class)->driver())->toBe($broadcaster);
    expect(invade(app(BroadcastManager::class)->driver())->formatChannels($channelNames))->toEqual($channelNames);
});

function getDiskPrefix(string $disk): string
{
    /** @var FilesystemAdapter $disk */
    $disk = Storage::disk($disk);
    $adapter = $disk->getAdapter();
    $prefix = invade(invade($adapter)->prefixer)->prefix;

    return $prefix;
}
