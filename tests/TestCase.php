<?php

namespace Stancl\Tenancy\Tests;

use Illuminate\Support\Facades\Redis;

class TestCase extends \Orchestra\Testbench\TestCase
{
    /**
     * Setup the test environment
     *
     * @return void
     */
    protected function setUp()
    {
        parent::setUp();

        Redis::connection('tenancy')->flushdb();

        tenant()->create('localhost');

        tenancy()->init('localhost');
    }

    /**
     * Define environment setup.
     *
     * @param  \Illuminate\Foundation\Application  $app
     * @return void
     */
    protected function getEnvironmentSetUp($app)
    {
        $app['config']->set('database.redis.client', 'phpredis');
        $app['config']->set('database.redis.tenancy', [
            'host' => env('TENANCY_TEST_REDIS_HOST', '127.0.0.1'),
            'password' => env('TENANCY_TEST_REDIS_PASSWORD', null),
            'port' => env('TENANCY_TEST_REDIS_PORT', 6379),
            // Use the #14 Redis database unless specified otherwise.
            // Make sure you don't store anything in this db!
            'database' => env('TENANCY_TEST_REDIS_DB', 14),
        ]);
        $app['config']->set('tenancy.database', [
            'based_on' => 'sqlite',
            'prefix' => 'tenant',
            'suffix' => '.sqlite',
        ]);
    }

    protected function getPackageProviders($app)
    {
        return [\Stancl\Tenancy\TenancyServiceProvider::class];
    }

    /**
     * Resolve application HTTP Kernel implementation.
     *
     * @param  \Illuminate\Foundation\Application  $app
     * @return void
     */
    protected function resolveApplicationHttpKernel($app)
    {
        $app->singleton('Illuminate\Contracts\Http\Kernel', \Stancl\Tenancy\Testing\HttpKernel::class);
    }
}
