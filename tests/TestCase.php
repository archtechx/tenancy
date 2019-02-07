<?php

namespace Stancl\Tenancy\Tests;

use Illuminate\Support\Facades\Redis;

class TestCase extends \Orchestra\Testbench\TestCase
{
    public $initTenancy = true;

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

        if ($this->initTenancy) {
            tenancy()->init('localhost');
        }
    }

    /**
     * Define environment setup.
     *
     * @param  \Illuminate\Foundation\Application  $app
     * @return void
     */
    protected function getEnvironmentSetUp($app)
    {
        if (file_exists(__DIR__ . '/../.env')) {
            (new \Dotenv\Dotenv(__DIR__ . '/..'))->load();
        }

        $app['config']->set([
            'database.redis.client' => 'phpredis',
            'database.redis.tenancy' => [
                'host' => env('TENANCY_TEST_REDIS_HOST', '127.0.0.1'),
                'password' => env('TENANCY_TEST_REDIS_PASSWORD', null),
                'port' => env('TENANCY_TEST_REDIS_PORT', 6379),
                // Use the #14 Redis database unless specified otherwise.
                // Make sure you don't store anything in this db!
                'database' => env('TENANCY_TEST_REDIS_DB', 14),
            ],
            'tenancy.database' => [
                'based_on' => 'sqlite',
                'prefix' => 'tenant',
                'suffix' => '.sqlite',
            ],
            'database.connections.sqlite.database' => ':memory:',
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
        $app->singleton('Illuminate\Contracts\Http\Kernel', HttpKernel::class);
    }

    public function randomString(int $length = 10)
    {
        return substr(str_shuffle(str_repeat($x = '0123456789abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ', ceil($length / strlen($x)))), 1, $length);
    }

    public function isTravis()
    {
        // Multiple, just to make sure. Someone might accidentally
        // set one of these environment vars on their computer.
        return env('CI') && env('TRAVIS') && env('CONTINUOUS_INTEGRATION');
    }
}
