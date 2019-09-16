<?php

declare(strict_types=1);

namespace Stancl\Tenancy\Tests;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Stancl\Tenancy\Tenant;

class DataSeparationTest extends TestCase
{
    public $autoCreateTenant = false;
    public $autoInitTenancy = false;

    /** @test */
    public function databases_are_separated()
    {
        $tenant1 = Tenant::create('tenant1.localhost');
        $tenant2 = Tenant::create('tenant2.localhost');
        \Artisan::call('tenants:migrate', [
            '--tenants' => [$tenant1['id'], $tenant2['id']],
        ]);

        tenancy()->init('tenant1.localhost');
        User::create([
            'name' => 'foo',
            'email' => 'foo@bar.com',
            'email_verified_at' => now(),
            'password' => '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', // password
            'remember_token' => Str::random(10),
        ]);
        $this->assertSame('foo', User::first()->name);

        tenancy()->init('tenant2.localhost');
        $this->assertSame(null, User::first());

        User::create([
            'name' => 'xyz',
            'email' => 'xyz@bar.com',
            'email_verified_at' => now(),
            'password' => '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', // password
            'remember_token' => Str::random(10),
        ]);

        $this->assertSame('xyz', User::first()->name);
        $this->assertSame('xyz@bar.com', User::first()->email);

        tenancy()->init('tenant1.localhost');
        $this->assertSame('foo', User::first()->name);
        $this->assertSame('foo@bar.com', User::first()->email);

        $tenant3 = Tenant::create('tenant3.localhost');
        \Artisan::call('tenants:migrate', [
            '--tenants' => [$tenant1['id'], $tenant3['id']],
        ]);

        tenancy()->init('tenant3.localhost');
        $this->assertSame(null, User::first());

        tenancy()->init('tenant1.localhost');
        DB::table('users')->where('id', 1)->update(['name' => 'xxx']);
        $this->assertSame('xxx', User::first()->name);
    }

    /** @test */
    public function redis_is_separated()
    {
        if (! config('tenancy.redis.tenancy')) {
            $this->markTestSkipped('Redis tenancy disabled.');
        }

        Tenant::create('tenant1.localhost');
        Tenant::create('tenant2.localhost');

        tenancy()->init('tenant1.localhost');
        Redis::set('foo', 'bar');
        $this->assertSame('bar', Redis::get('foo'));

        tenancy()->init('tenant2.localhost');
        $this->assertSame(null, Redis::get('foo'));
        Redis::set('foo', 'xyz');
        Redis::set('abc', 'def');
        $this->assertSame('xyz', Redis::get('foo'));
        $this->assertSame('def', Redis::get('abc'));

        tenancy()->init('tenant1.localhost');
        $this->assertSame('bar', Redis::get('foo'));
        $this->assertSame(null, Redis::get('abc'));

        Tenant::create('tenant3.localhost');
        tenancy()->init('tenant3.localhost');
        $this->assertSame(null, Redis::get('foo'));
        $this->assertSame(null, Redis::get('abc'));
    }

    /** @test */
    public function cache_is_separated()
    {
        Tenant::create('tenant1.localhost');
        Tenant::create('tenant2.localhost');

        tenancy()->init('tenant1.localhost');
        Cache::put('foo', 'bar', 60);
        $this->assertSame('bar', Cache::get('foo'));

        tenancy()->init('tenant2.localhost');
        $this->assertSame(null, Cache::get('foo'));
        Cache::put('foo', 'xyz', 60);
        Cache::put('abc', 'def', 60);
        $this->assertSame('xyz', Cache::get('foo'));
        $this->assertSame('def', Cache::get('abc'));

        tenancy()->init('tenant1.localhost');
        $this->assertSame('bar', Cache::get('foo'));
        $this->assertSame(null, Cache::get('abc'));

        Tenant::create('tenant3.localhost');
        tenancy()->init('tenant3.localhost');
        $this->assertSame(null, Cache::get('foo'));
        $this->assertSame(null, Cache::get('abc'));
    }

    /** @test */
    public function filesystem_is_separated()
    {
        Tenant::create('tenant1.localhost');
        Tenant::create('tenant2.localhost');

        tenancy()->init('tenant1.localhost');
        Storage::disk('public')->put('foo', 'bar');
        $this->assertSame('bar', Storage::disk('public')->get('foo'));

        tenancy()->init('tenant2.localhost');
        $this->assertFalse(Storage::disk('public')->exists('foo'));
        Storage::disk('public')->put('foo', 'xyz');
        Storage::disk('public')->put('abc', 'def');
        $this->assertSame('xyz', Storage::disk('public')->get('foo'));
        $this->assertSame('def', Storage::disk('public')->get('abc'));

        tenancy()->init('tenant1.localhost');
        $this->assertSame('bar', Storage::disk('public')->get('foo'));
        $this->assertFalse(Storage::disk('public')->exists('abc'));

        Tenant::create('tenant3.localhost');
        tenancy()->init('tenant3.localhost');
        $this->assertFalse(Storage::disk('public')->exists('foo'));
        $this->assertFalse(Storage::disk('public')->exists('abc'));
    }
}

class User extends \Illuminate\Database\Eloquent\Model
{
    protected $guarded = [];
}
