<?php

declare(strict_types = 1);

namespace Stancl\Tenancy\Tests;

use Illuminate\Bus\BatchRepository;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use ReflectionClass;
use Stancl\Tenancy\Bootstrappers\BatchTenancyBootstrapper;
use Stancl\Tenancy\Bootstrappers\DatabaseTenancyBootstrapper;
use Stancl\Tenancy\Events\TenancyEnded;
use Stancl\Tenancy\Events\TenancyInitialized;
use Stancl\Tenancy\Listeners\BootstrapTenancy;
use Stancl\Tenancy\Listeners\RevertToCentralContext;
use Stancl\Tenancy\Tests\Etc\Tenant;

class BatchTest extends TestCase
{
    public function setUp(): void
    {
        parent::setUp();

        $this->app->singleton(BatchTenancyBootstrapper::class);

        config([
            'tenancy.bootstrappers' => [
                DatabaseTenancyBootstrapper::class,
                BatchTenancyBootstrapper::class,
            ],
        ]);

        Event::listen(TenancyInitialized::class, BootstrapTenancy::class);
        Event::listen(TenancyEnded::class, RevertToCentralContext::class);
    }

    /** @test */
    public function batch_repository_is_set_to_tenant_connection_and_reverted()
    {
        if (! version_compare(app()->version(), '8.0', '>=')) {
            $this->markTestSkipped('Job batches are only supported in Laravel 8+');
        }

        $tenant = Tenant::create();

        $this->assertEquals('central', $this->getBatchRepositoryConnectionName(), 'Expected initial connection to be central');

        tenancy()->initialize($tenant);

        $this->assertEquals('tenant', $this->getBatchRepositoryConnectionName(), 'Expected tenant connection to be tenant');

        tenancy()->end();

        $this->assertEquals('central', $this->getBatchRepositoryConnectionName(), 'Expected the reverted connection to be central');
    }


    private function getBatchRepositoryConnectionName(): string
    {
        $batchRepository = app(BatchRepository::class);

        $batchRepositoryReflection = new ReflectionClass($batchRepository);
        $connectionProperty        = $batchRepositoryReflection->getProperty('connection');
        $connectionProperty->setAccessible(true);
        $connection = $connectionProperty->getValue($batchRepository);

        return $connection->getName();
    }

}
