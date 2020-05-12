<?php

namespace Stancl\Tenancy\Tests\v3;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Storage;
use Stancl\Tenancy\Database\Models\Tenant;
use Stancl\Tenancy\Events\Listeners\BootstrapTenancy;
use Stancl\Tenancy\Events\Listeners\JobPipeline;
use Stancl\Tenancy\Events\Listeners\RevertToCentralContext;
use Stancl\Tenancy\Events\TenancyEnded;
use Stancl\Tenancy\Events\TenancyInitialized;
use Stancl\Tenancy\Events\TenantCreated;
use Stancl\Tenancy\Jobs\CreateDatabase;
use Stancl\Tenancy\TenancyBootstrappers\CacheTenancyBootstrapper;
use Stancl\Tenancy\TenancyBootstrappers\DatabaseTenancyBootstrapper;
use Stancl\Tenancy\TenancyBootstrappers\FilesystemTenancyBootstrapper;
use Stancl\Tenancy\TenancyBootstrappers\RedisTenancyBootstrapper;
use Stancl\Tenancy\Tests\TestCase;

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
            DatabaseTenancyBootstrapper::class
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
                CacheTenancyBootstrapper::class
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
            RedisTenancyBootstrapper::class
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
            FilesystemTenancyBootstrapper::class
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

        // Check suffixing logic
        $new_storage_path = storage_path();
        $this->assertEquals($old_storage_path . '/' . config('tenancy.filesystem.suffix_base') . tenant('id'), $new_storage_path);

        foreach (config('tenancy.filesystem.disks') as $disk) {
            $suffix = config('tenancy.filesystem.suffix_base') . tenant('id');
            $current_path_prefix = Storage::disk($disk)->getAdapter()->getPathPrefix();

            if ($override = config("tenancy.filesystem.root_override.{$disk}")) {
                $correct_path_prefix = str_replace('%storage_path%', storage_path(), $override);
            } else {
                if ($base = $old_storage_facade_roots[$disk]) {
                    $correct_path_prefix = $base . "/$suffix/";
                } else {
                    $correct_path_prefix = "$suffix/";
                }
            }

            $this->assertSame($correct_path_prefix, $current_path_prefix);
        }
    }

    // for queues see QueueTest
}
