<?php

declare(strict_types=1);

namespace Stancl\Tenancy\Tests;

use Illuminate\Support\Facades\Redis;
use Stancl\Tenancy\StorageDrivers\RedisStorageDriver;
use Stancl\Tenancy\StorageDrivers\DatabaseStorageDriver;

abstract class TestCase extends \Orchestra\Testbench\TestCase
{
    public $autoCreateTenant = true;
    public $autoInitTenancy = true;

    /**
     * Setup the test environment.
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        Redis::connection('tenancy')->flushdb();
        Redis::connection('cache')->flushdb();

        $this->loadMigrationsFrom([
            '--path' => \realpath(__DIR__ . '/../assets/migrations'),
            '--database' => 'central',
        ]);
        config(['database.default' => 'sqlite']); // fix issue caused by loadMigrationsFrom

        if ($this->autoCreateTenant) {
            $this->createTenant();
        }

        if ($this->autoInitTenancy) {
            $this->initTenancy();
        }
    }

    protected function tearDown(): void
    {
        // config(['database.default' => 'central']);

        parent::tearDown();
    }

    public function createTenant($domain = 'localhost')
    {
        tenant()->create($domain);
    }

    public function initTenancy($domain = 'localhost')
    {
        return tenancy()->init($domain);
    }

    /**
     * Define environment setup.
     *
     * @param  \Illuminate\Foundation\Application  $app
     * @return void
     */
    protected function getEnvironmentSetUp($app)
    {
        if (\file_exists(__DIR__ . '/../.env')) {
            \Dotenv\Dotenv::create(__DIR__ . '/..')->load();
        }

        \fclose(\fopen(database_path('central.sqlite'), 'w'));

        $app['config']->set([
            'database.redis.cache.host' => env('TENANCY_TEST_REDIS_HOST', '127.0.0.1'),
            'database.redis.default.host' => env('TENANCY_TEST_REDIS_HOST', '127.0.0.1'),
            'database.redis.options.prefix' => 'foo',
            'database.redis.tenancy' => [
                'host' => env('TENANCY_TEST_REDIS_HOST', '127.0.0.1'),
                'password' => env('TENANCY_TEST_REDIS_PASSWORD', null),
                'port' => env('TENANCY_TEST_REDIS_PORT', 6379),
                // Use the #14 Redis database unless specified otherwise.
                // Make sure you don't store anything in this db!
                'database' => env('TENANCY_TEST_REDIS_DB', 14),
                'prefix' => 'abc', // todo unrelated to tenancy, but this doesn't seem to have an effect? try to replicate in a fresh laravel installation
            ],
            'database.connections.central' => [
                'driver' => 'sqlite',
                'database' => database_path('central.sqlite'),
            ],
            'tenancy.database' => [
                'based_on' => 'sqlite',
                'prefix' => 'tenant',
                'suffix' => '.sqlite',
            ],
            'database.connections.sqlite.database' => ':memory:',
            'database.connections.mysql.host' => env('TENANCY_TEST_MYSQL_HOST', '127.0.0.1'),
            'database.connections.pgsql.host' => env('TENANCY_TEST_PGSQL_HOST', '127.0.0.1'),
            'tenancy.filesystem.disks' => [
                'local',
                'public',
                's3',
            ],
            'tenancy.redis.tenancy' => env('TENANCY_TEST_REDIS_TENANCY', true),
            'database.redis.client' => env('TENANCY_TEST_REDIS_CLIENT', 'phpredis'),
            'tenancy.redis.prefixed_connections' => ['default'],
            'tenancy.migrations_directory' => database_path('../migrations'),
        ]);

        if (env('TENANCY_TEST_STORAGE_DRIVER', 'redis') === 'redis') {
            $app['config']->set([
                'tenancy.storage_driver' => RedisStorageDriver::class,
            ]);

            tenancy()->storage = $app->make(RedisStorageDriver::class);
        } elseif (env('TENANCY_TEST_STORAGE_DRIVER', 'redis') === 'db') {
            $app['config']->set([
                'tenancy.storage_driver' => DatabaseStorageDriver::class,
            ]);

            tenancy()->storage = $app->make(DatabaseStorageDriver::class);
        }
    }

    protected function getPackageProviders($app)
    {
        return [
            \Stancl\Tenancy\TenancyServiceProvider::class,
        ];
    }

    protected function getPackageAliases($app)
    {
        return [
            'Tenancy' => \Stancl\Tenancy\TenancyFacade::class,
            'Tenant' => \Stancl\Tenancy\TenancyFacade::class,
            'GlobalCache' => \Stancl\Tenancy\GlobalCacheFacade::class,
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

    /**
     * Resolve application Console Kernel implementation.
     *
     * @param  \Illuminate\Foundation\Application  $app
     * @return void
     */
    protected function resolveApplicationConsoleKernel($app)
    {
        $app->singleton('Illuminate\Contracts\Console\Kernel', Etc\ConsoleKernel::class);
    }

    public function randomString(int $length = 10)
    {
        return \substr(\str_shuffle(\str_repeat($x = '0123456789abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ', (int) (\ceil($length / \strlen($x))))), 1, $length);
    }

    public function isContainerized()
    {
        return env('CONTINUOUS_INTEGRATION') || env('DOCKER');
    }

    public function assertArrayIsSubset($subset, $array, string $message = ''): void
    {
        parent::assertTrue(\array_intersect($subset, $array) == $subset, $message);
    }
}
