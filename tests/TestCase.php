<?php

namespace Stancl\Tenancy\Tests;

use Illuminate\Support\Facades\Redis;

abstract class TestCase extends \Orchestra\Testbench\TestCase
{
    public $autoCreateTenant = true;
    public $autoInitTenancy = true;

    private function checkRequirements(): void
    {
        parent::checkRequirements();

        dd($this->getAnnotations());
    }

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
        if (file_exists(__DIR__ . '/../.env')) {
            $this->loadDotEnv();
        }

        $app['config']->set([
            'database.redis.client' => 'phpredis',
            'database.redis.cache.host' => env('TENANCY_TEST_REDIS_HOST', '127.0.0.1'),
            'database.redis.default.host' => env('TENANCY_TEST_REDIS_HOST', '127.0.0.1'),
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
            'database.connections.mysql.host' => env('TENANCY_TEST_MYSQL_HOST', '127.0.0.1'),
            'database.connections.pgsql.host' => env('TENANCY_TEST_PGSQL_HOST', '127.0.0.1'),
            'tenancy.filesystem.disks' => [
                'local',
                'public',
                's3',
            ],
            'tenancy.redis.tenancy' => true,
            'tenancy.redis.prefixed_connections' => ['default'],
            'tenancy.migrations_directory' => database_path('../migrations'),
        ]);

        switch ((string) env('STANCL_TENANCY_TEST_VARIANT', '1')) {
            case '2':
                $app['config']->set([
                    'tenancy.redis.tenancy' => true,
                    'database.redis.client' => 'phpredis',
                ]);
                break;
            default:
                $app['config']->set([
                    'tenancy.redis.tenancy' => false,
                    'database.redis.client' => 'predis',
                ]);
        }
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
            'Tenancy' => \Stancl\Tenancy\TenancyFacade::class,
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

    public function randomString(int $length = 10)
    {
        return substr(str_shuffle(str_repeat($x = '0123456789abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ', ceil($length / strlen($x)))), 1, $length);
    }

    public function isContainerized()
    {
        return env('CONTINUOUS_INTEGRATION') || env('DOCKER');
    }

    public function assertArrayIsSubset($subset, $array, string $message = ''): void
    {
        parent::assertTrue(array_intersect($subset, $array) == $subset, $message);
    }
}
