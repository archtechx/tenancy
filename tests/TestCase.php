<?php

declare(strict_types=1);

namespace Stancl\Tenancy\Tests;

use PDO;
use Dotenv\Dotenv;
use Aws\DynamoDb\DynamoDbClient;
use Stancl\Tenancy\Tests\Etc\Tenant;
use Illuminate\Support\Facades\Redis;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\Artisan;
use Stancl\Tenancy\Facades\GlobalCache;
use Stancl\Tenancy\TenancyServiceProvider;
use Stancl\Tenancy\Facades\Tenancy as TenancyFacade;
use Stancl\Tenancy\Bootstrappers\RootUrlBootstrapper;
use Stancl\Tenancy\Bootstrappers\MailConfigBootstrapper;
use Stancl\Tenancy\Bootstrappers\PostgresRLSBootstrapper;
use Stancl\Tenancy\Bootstrappers\CacheTenancyBootstrapper;
use Stancl\Tenancy\Bootstrappers\RedisTenancyBootstrapper;
use Stancl\Tenancy\Bootstrappers\UrlGeneratorBootstrapper;
use Stancl\Tenancy\Bootstrappers\BroadcastingConfigBootstrapper;
use Stancl\Tenancy\Bootstrappers\BroadcastChannelPrefixBootstrapper;
use Stancl\Tenancy\Bootstrappers\FilesystemTenancyBootstrapper;
use function Stancl\Tenancy\Tests\pest;

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
        Artisan::call('cache:clear memcached'); // flush memcached
        Artisan::call('cache:clear file'); // flush file cache
        apcu_clear_cache(); // flush APCu cache

        // re-create dynamodb `cache` table
        $dynamodb = new DynamoDbClient([
            'region' => 'us-east-1',
            'version' => 'latest',
            'endpoint' => 'http://dynamodb:8000',
            'credentials' => [
                'key' => env('TENANCY_TEST_DYNAMODB_KEY', 'DUMMYIDEXAMPLE'),
                'secret' => env('TENANCY_TEST_DYNAMODB_KEY', 'DUMMYEXAMPLEKEY'),
            ],
        ]);

        try {
            $dynamodb->deleteTable([
                'TableName' => 'cache',
            ]);
        } catch (\Throwable) {}

        $dynamodb->createTable([
            'TableName' => 'cache',
            'KeySchema' => [
                [
                    'AttributeName' => 'key', // Partition key
                    'KeyType' => 'HASH',
                ]
            ],
            'AttributeDefinitions' => [
                [
                    'AttributeName' => 'key',
                    'AttributeType' => 'S', // String
                ]
            ],
            'ProvisionedThroughput' => [
                'ReadCapacityUnits' => 100,
                'WriteCapacityUnits' => 100,
            ],
        ]);

        file_put_contents(database_path('central.sqlite'), '');

        pest()->artisan('migrate:fresh', [
            '--force' => true,
            '--path' => __DIR__ . '/../assets/migrations',
            '--realpath' => true,
        ]);

        \Illuminate\Testing\TestResponse::macro('assertContent', function ($content) {
            \Illuminate\Testing\Assert::assertSame($content, $this->baseResponse->getContent());

            return $this;
        });
    }

    /**
     * Define environment setup.
     *
     * @param  Application  $app
     * @return void
     */
    protected function getEnvironmentSetUp($app)
    {
        if (file_exists(__DIR__ . '/../.env')) {
            Dotenv::createImmutable(__DIR__ . '/..')->load();
        }

        $app['config']->set([
            'database.default' => 'central',
            'cache.default' => 'redis',
            'session.driver' => 'redis',
            'database.redis.cache.host' => env('TENANCY_TEST_REDIS_HOST', 'redis'),
            'database.redis.default.host' => env('TENANCY_TEST_REDIS_HOST', 'redis'),
            'database.redis.options.prefix' => 'foo',
            'database.redis.client' => 'predis',
            'cache.stores.memcached.servers.0.host' => env('TENANCY_TEST_MEMCACHED_HOST', 'memcached'),
            'cache.stores.dynamodb.key' => env('TENANCY_TEST_DYNAMODB_KEY', 'DUMMYIDEXAMPLE'),
            'cache.stores.dynamodb.secret' => env('TENANCY_TEST_DYNAMODB_SECRET', 'DUMMYEXAMPLEKEY'),
            'cache.stores.dynamodb.endpoint' => 'http://dynamodb:8000',
            'cache.stores.dynamodb.region' => 'us-east-1',
            'cache.stores.dynamodb.table' => 'cache',
            'cache.stores.apc' => ['driver' => 'apc'],
            'database.connections.central' => [
                'driver' => 'mysql',
                'url' => env('DATABASE_URL'),
                'host' => 'mysql',
                'port' => env('DB_PORT', '3306'),
                'database' => 'main',
                'username' => env('DB_USERNAME', 'forge'),
                'password' => env('DB_PASSWORD', ''),
                'unix_socket' => env('DB_SOCKET', ''),
                'charset' => 'utf8mb4',
                'collation' => 'utf8mb4_unicode_ci',
                'prefix' => '',
                'prefix_indexes' => true,
                'strict' => true,
                'engine' => null,
                'options' => extension_loaded('pdo_mysql') ? array_filter([
                    PDO::MYSQL_ATTR_SSL_CA => env('MYSQL_ATTR_SSL_CA'),
                ]) : [],
            ],
            'database.connections.sqlite.database' => ':memory:',
            'database.connections.mysql.charset' => 'utf8mb4',
            'database.connections.mysql.collation' => 'utf8mb4_unicode_ci',
            'database.connections.mysql.host' => env('TENANCY_TEST_MYSQL_HOST', '127.0.0.1'),
            'database.connections.sqlsrv.username' => env('TENANCY_TEST_SQLSRV_USERNAME', 'sa'),
            'database.connections.sqlsrv.password' => env('TENANCY_TEST_SQLSRV_PASSWORD', 'P@ssword'),
            'database.connections.sqlsrv.host' => env('TENANCY_TEST_SQLSRV_HOST', '127.0.0.1'),
            'database.connections.sqlsrv.database' => null,
            'database.connections.sqlsrv.trust_server_certificate' => true,
            'database.connections.pgsql.host' => env('TENANCY_TEST_PGSQL_HOST', '127.0.0.1'),
            'tenancy.filesystem.disks' => [
                'local',
                'public',
                's3',
            ],
            'filesystems.disks.s3.bucket' => 'foo',
            'tenancy.redis.tenancy' => env('TENANCY_TEST_REDIS_TENANCY', true),
            'database.redis.client' => env('TENANCY_TEST_REDIS_CLIENT', 'phpredis'),
            'tenancy.redis.prefixed_connections' => ['default'],
            'tenancy.migration_parameters' => [
                '--path' => [database_path('../migrations')],
                '--realpath' => true,
                '--force' => true,
            ],
            'tenancy.identification.central_domains' => ['localhost', '127.0.0.1'],
            'tenancy.bootstrappers' => [],
            'queue.connections.central' => [
                'driver' => 'sync',
                'central' => true,
            ],
            'tenancy.seeder_parameters' => [],
            'tenancy.models.tenant' => Tenant::class, // Use test tenant w/ DBs & domains
        ]);

        // Since we run the TSP with no bootstrappers enabled, we need
        // to manually register bootstrappers as singletons here.
        $app->singleton(RedisTenancyBootstrapper::class);
        $app->singleton(CacheTenancyBootstrapper::class);
        $app->singleton(BroadcastingConfigBootstrapper::class);
        $app->singleton(BroadcastChannelPrefixBootstrapper::class);
        $app->singleton(PostgresRLSBootstrapper::class);
        $app->singleton(MailConfigBootstrapper::class);
        $app->singleton(RootUrlBootstrapper::class);
        $app->singleton(UrlGeneratorBootstrapper::class);
        $app->singleton(FilesystemTenancyBootstrapper::class);
    }

    protected function getPackageProviders($app)
    {
        TenancyServiceProvider::$configure = function () {
            config(['tenancy.bootstrappers' => []]);
        };

        return [
            TenancyServiceProvider::class,
        ];
    }

    protected function getPackageAliases($app)
    {
        return [
            'Tenancy' => TenancyFacade::class,
            'GlobalCache' => GlobalCache::class,
        ];
    }

    /**
     * Resolve application HTTP Kernel implementation.
     *
     * @param  Application  $app
     * @return void
     */
    protected function resolveApplicationHttpKernel($app)
    {
        $app->singleton('Illuminate\Contracts\Http\Kernel', Etc\HttpKernel::class);
    }

    /**
     * Resolve application Console Kernel implementation.
     *
     * @param  Application  $app
     * @return void
     */
    protected function resolveApplicationConsoleKernel($app)
    {
        $app->singleton('Illuminate\Contracts\Console\Kernel', Etc\Console\ConsoleKernel::class);
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
