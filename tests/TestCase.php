<?php

declare(strict_types=1);

namespace Stancl\Tenancy\Tests;

use Illuminate\Support\Facades\Redis;
use Stancl\Tenancy\Tests\Etc\Tenant;

abstract class TestCase extends \Orchestra\Testbench\TestCase
{
    /**
     * Setup the test environment.
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        Redis::connection('default')->flushdb();
        Redis::connection('cache')->flushdb();

        file_put_contents(database_path('central.sqlite'), '');
        $this->artisan('migrate:fresh', [
            '--force' => true,
            '--path' => __DIR__ . '/../assets/migrations',
            '--realpath' => true,
        ]);

        // Laravel 6.x support
        $testResponse = class_exists('Illuminate\Testing\TestResponse') ? 'Illuminate\Testing\TestResponse' : 'Illuminate\Foundation\Testing\TestResponse';
        $testResponse::macro('assertContent', function ($content) {
            $assertClass = class_exists('Illuminate\Testing\Assert') ? 'Illuminate\Testing\Assert' : 'Illuminate\Foundation\Testing\Assert';
            $assertClass::assertSame($content, $this->baseResponse->getContent());

            return $this;
        });
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
            \Dotenv\Dotenv::create(__DIR__ . '/..')->load();
        }

        $app['config']->set([
            'database.default' => 'central',
            'database.redis.cache.host' => env('TENANCY_TEST_REDIS_HOST', '127.0.0.1'),
            'database.redis.default.host' => env('TENANCY_TEST_REDIS_HOST', '127.0.0.1'),
            'database.redis.options.prefix' => 'foo',
            'database.connections.central' => [
                'driver' => 'sqlite',
                'database' => database_path('central.sqlite'),
                // 'database' => ':memory:',
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
            'tenancy.migration_parameters' => [
                '--path' => [database_path('../migrations')],
                '--realpath' => true,
                '--force' => true,
            ],
            'tenancy.storage.connection' => 'central',
            'tenancy.bootstrappers.redis' => \Stancl\Tenancy\Bootstrappers\RedisTenancyBootstrapper::class,
            'queue.connections.central' => [
                'driver' => 'sync',
                'central' => true,
            ],
            'tenancy.seeder_parameters' => [],
            'tenancy.tenant_model' => Tenant::class, // Use test tenant w/ DBs & domains
        ]);

        $app->singleton(\Stancl\Tenancy\Bootstrappers\RedisTenancyBootstrapper::class);
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
            'Tenancy' => \Stancl\Tenancy\Facades\Tenancy::class,
            'GlobalCache' => \Stancl\Tenancy\Facades\GlobalCache::class,
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
        return substr(str_shuffle(str_repeat($x = '0123456789abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ', (int) (ceil($length / strlen($x))))), 1, $length);
    }

    public function assertArrayIsSubset($subset, $array, string $message = ''): void
    {
        parent::assertTrue(array_intersect($subset, $array) == $subset, $message);
    }
}
