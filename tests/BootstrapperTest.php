<?php

declare(strict_types=1);

namespace Stancl\Tenancy\Tests;

use Illuminate\Filesystem\FilesystemAdapter;
use ReflectionObject;
use ReflectionProperty;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\DB;
use Stancl\JobPipeline\JobPipeline;
use Stancl\Tenancy\Tests\Etc\Tenant;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Storage;
use Stancl\Tenancy\Events\TenancyEnded;
use Stancl\Tenancy\Jobs\CreateDatabase;
use Stancl\Tenancy\Events\TenantCreated;
use Stancl\Tenancy\Events\TenancyInitialized;
use Stancl\Tenancy\Listeners\BootstrapTenancy;
use Stancl\Tenancy\Listeners\RevertToCentralContext;
use Stancl\Tenancy\Bootstrappers\CacheTenancyBootstrapper;
use Stancl\Tenancy\Bootstrappers\RedisTenancyBootstrapper;
use Stancl\Tenancy\Bootstrappers\DatabaseTenancyBootstrapper;
use Stancl\Tenancy\Bootstrappers\FilesystemTenancyBootstrapper;

class BootstrapperTest extends TestCase
{
    public $mockConsoleOutput = false;

    public function setUp(): void
    {
        parent::setUp();

        Event::listen(
            TenantCreated::class,
            JobPipeline::make([CreateDatabase::class])->send(function (TenantCreated $event) {
                return $event->tenant;
            })->toListener()
        );

        Event::listen(TenancyInitialized::class, BootstrapTenancy::class);
        Event::listen(TenancyEnded::class, RevertToCentralContext::class);
    }

    /** @test */
    public function database_data_is_separated()
    {
        config(['tenancy.bootstrappers' => [
            DatabaseTenancyBootstrapper::class,
        ]]);

        $tenant1 = Tenant::create();
        $tenant2 = Tenant::create();

        $this->artisan('tenants:migrate');

        tenancy()->initialize($tenant1);

        // Create Foo user
        DB::table('users')->insert(['name' => 'Foo', 'email' => 'foo@bar.com', 'password' => 'secret']);
        $this->assertCount(1, DB::table('users')->get());

        tenancy()->initialize($tenant2);

        // Assert Foo user is not in this DB
        $this->assertCount(0, DB::table('users')->get());
        // Create Bar user
        DB::table('users')->insert(['name' => 'Bar', 'email' => 'bar@bar.com', 'password' => 'secret']);
        $this->assertCount(1, DB::table('users')->get());

        tenancy()->initialize($tenant1);

        // Assert Bar user is not in this DB
        $this->assertCount(1, DB::table('users')->get());
        $this->assertSame('Foo', DB::table('users')->first()->name);
    }

    /** @test */
    public function cache_data_is_separated()
    {
        config([
            'tenancy.bootstrappers' => [
                CacheTenancyBootstrapper::class,
            ],
            'cache.default' => 'redis',
        ]);

        $tenant1 = Tenant::create();
        $tenant2 = Tenant::create();

        cache()->set('foo', 'central');
        $this->assertSame('central', Cache::get('foo'));

        tenancy()->initialize($tenant1);

        // Assert central cache doesn't leak to tenant context
        $this->assertFalse(Cache::has('foo'));

        cache()->set('foo', 'bar');
        $this->assertSame('bar', Cache::get('foo'));

        tenancy()->initialize($tenant2);

        // Assert one tenant's data doesn't leak to another tenant
        $this->assertFalse(Cache::has('foo'));

        cache()->set('foo', 'xyz');
        $this->assertSame('xyz', Cache::get('foo'));

        tenancy()->initialize($tenant1);

        // Asset data didn't leak to original tenant
        $this->assertSame('bar', Cache::get('foo'));

        tenancy()->end();

        // Asset central is still the same
        $this->assertSame('central', Cache::get('foo'));
    }

    /** @test */
    public function redis_data_is_separated()
    {
        config(['tenancy.bootstrappers' => [
            RedisTenancyBootstrapper::class,
        ]]);

        $tenant1 = Tenant::create();
        $tenant2 = Tenant::create();

        tenancy()->initialize($tenant1);
        Redis::set('foo', 'bar');
        $this->assertSame('bar', Redis::get('foo'));

        tenancy()->initialize($tenant2);
        $this->assertSame(null, Redis::get('foo'));
        Redis::set('foo', 'xyz');
        Redis::set('abc', 'def');
        $this->assertSame('xyz', Redis::get('foo'));
        $this->assertSame('def', Redis::get('abc'));

        tenancy()->initialize($tenant1);
        $this->assertSame('bar', Redis::get('foo'));
        $this->assertSame(null, Redis::get('abc'));

        $tenant3 = Tenant::create();
        tenancy()->initialize($tenant3);
        $this->assertSame(null, Redis::get('foo'));
        $this->assertSame(null, Redis::get('abc'));
    }

    /** @test */
    public function filesystem_data_is_separated()
    {
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
        $this->assertSame('bar', Storage::disk('public')->get('foo'));

        tenancy()->initialize($tenant2);
        $this->assertFalse(Storage::disk('public')->exists('foo'));
        Storage::disk('public')->put('foo', 'xyz');
        Storage::disk('public')->put('abc', 'def');
        $this->assertSame('xyz', Storage::disk('public')->get('foo'));
        $this->assertSame('def', Storage::disk('public')->get('abc'));

        tenancy()->initialize($tenant1);
        $this->assertSame('bar', Storage::disk('public')->get('foo'));
        $this->assertFalse(Storage::disk('public')->exists('abc'));

        $tenant3 = Tenant::create();
        tenancy()->initialize($tenant3);
        $this->assertFalse(Storage::disk('public')->exists('foo'));
        $this->assertFalse(Storage::disk('public')->exists('abc'));

        $expected_storage_path = $old_storage_path . '/tenant' . tenant('id'); // /tenant = suffix base

        // Check that disk prefixes respect the root_override logic
        $this->assertSame($expected_storage_path . '/app/', $this->getDiskPrefix('local'));
        $this->assertSame($expected_storage_path . '/app/public/', $this->getDiskPrefix('public'));
        $this->assertSame('tenant' . tenant('id') . '/', $this->getDiskPrefix('s3'), '/');

        // Check suffixing logic
        $new_storage_path = storage_path();
        $this->assertEquals($expected_storage_path, $new_storage_path);
    }

    protected function getDiskPrefix(string $disk): string
    {
        /** @var FilesystemAdapter $disk */
        $disk = Storage::disk($disk);
        $adapter = $disk->getAdapter();

        $prefixer = (new ReflectionObject($adapter))->getProperty('prefixer');
        $prefixer->setAccessible(true);

        // reflection -> instance
        $prefixer = $prefixer->getValue($adapter);

        $prefix = (new ReflectionProperty($prefixer, 'prefix'));
        $prefix->setAccessible(true);

        return $prefix->getValue($prefixer);
    }

    // for queues see QueueTest
}
