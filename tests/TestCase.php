<?php

namespace Stancl\Tenancy\Tests;

use Illuminate\Support\Facades\Redis;

abstract class TestCase extends \Orchestra\Testbench\TestCase
{
    public $autoCreateTenant = true;
    public $autoInitTenancy = true;

    /**
     * Setup the test environment
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        Redis::connection('tenancy')->flushdb();

        if ($this->autoCreateTenant) {
            $this->createTenant();
        }

        if ($this->autoInitTenancy) {
            $this->initTenancy();
        }
    }

    public function createTenant($domain = 'localhost')
    {
        tenant()->create($domain);
    }

    public function initTenancy($domain = 'localhost')
    {
        tenancy()->init($domain);
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
            $this->loadDotEnv();
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
            'tenancy.filesystem.disks' => [
                'local',
                'public',
                's3',
            ],
            'tenancy.migrations_directory' => database_path('../migrations'),
        ]);
    }

    protected function loadDotEnv()
    {
        if (app()::VERSION > '5.8.0') {
            \Dotenv\Dotenv::create(__DIR__ . '/..')->load();
        } else {
            (new \Dotenv\Dotenv(__DIR__ . '/..'))->load();
        }
    }

    protected function getPackageProviders($app)
    {
        return [\Stancl\Tenancy\TenancyServiceProvider::class];
    }

    protected function getPackageAliases($app)
    {
        return [
            'Tenancy' => \Stancl\Tenancy\TenancyFacade::class
        ];
    }

    /**
     * Resolve application HTTP Kernel implementation.
     *
     * @param  \Illuminate\Foundation\Application  $app
     * @return void
     */
    protected function resolveApplicationHttpKernel($app)
    {
        $app->singleton('Illuminate\Contracts\Http\Kernel', Etc\HttpKernel::class);
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

    public function assertArrayIsSubset($subset, $array, string $message = ''): void
    {
        parent::assertTrue(array_intersect($subset, $array) == $subset, $message);
    }
}
