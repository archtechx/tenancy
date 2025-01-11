<?php

declare(strict_types=1);

namespace Stancl\Tenancy\Bootstrappers;

use Illuminate\Config\Repository;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Queue\Events\JobRetryRequested;
use Illuminate\Queue\QueueManager;
use Illuminate\Support\Testing\Fakes\QueueFake;
use Stancl\Tenancy\Contracts\TenancyBootstrapper;
use Stancl\Tenancy\Contracts\Tenant;

class PersistentQueueTenancyBootstrapper implements TenancyBootstrapper
{
    /** @var Repository */
    protected $config;

    /** @var QueueManager */
    protected $queue;

    /**
     * The normal constructor is only executed after tenancy is bootstrapped.
     * However, we're registering a hook to initialize tenancy. Therefore,
     * we need to register the hook at service provider execution time.
     */
    public static function __constructStatic(Application $app): void
    {
        static::setUpJobListener($app->make(Dispatcher::class), $app->runningUnitTests());
    }

    public function __construct(Repository $config, QueueManager $queue)
    {
        $this->config = $config;
        $this->queue = $queue;

        $this->setUpPayloadGenerator();
    }

    protected static function setUpJobListener(Dispatcher $dispatcher, bool $runningTests): void
    {
        $previousTenant = null;

        $dispatcher->listen(JobProcessing::class, function ($event) use (&$previousTenant) {
            $previousTenant = tenant();

            static::initializeTenancyForQueue($event->job->payload()['tenant_id'] ?? null);
        });

        $dispatcher->listen(JobRetryRequested::class, function ($event) use (&$previousTenant) {
            $previousTenant = tenant();

            static::initializeTenancyForQueue($event->payload()['tenant_id'] ?? null);
        });

        // If we're running tests, we make sure to clean up after any artisan('queue:work') calls
        $revertToPreviousState = function ($event) use (&$previousTenant, $runningTests) {
            if ($runningTests) {
                static::revertToPreviousState($event->job->payload()['tenant_id'] ?? null, $previousTenant);

                // We don't need to reset $previousTenant since the value will be set again when a job is processed.
            }

            // If we're not running tests, we remain in the tenant's context. This makes other JobProcessed
            // listeners able to deserialize the job, including with SerializesModels, since the tenant connection
            // remains open.
        };

        $dispatcher->listen(JobProcessed::class, $revertToPreviousState); // artisan('queue:work') which succeeds
        $dispatcher->listen(JobFailed::class, $revertToPreviousState); // artisan('queue:work') which fails
    }

    protected static function initializeTenancyForQueue(string|int|null $tenantId): void
    {
        if (! $tenantId) {
            // The job is not tenant-aware
            if (tenancy()->initialized) {
                // Tenancy was initialized, so we revert back to the central context
                tenancy()->end();
            }

            return;
        }

        // Re-initialize tenancy between all jobs even if the tenant is the same
        // so that we don't work with an outdated tenant() instance in case it
        // was updated outside the queue worker.
        tenancy()->end();

        /** @var Tenant $tenant */
        $tenant = tenancy()->find($tenantId);
        tenancy()->initialize($tenant);
    }

    protected static function revertToPreviousState(string|int|null $tenantId, ?Tenant $previousTenant): void
    {
        // The job was not tenant-aware
        if (! $tenantId) {
            return;
        }

        // Revert back to the previous tenant
        if (tenant() && $previousTenant && $previousTenant->isNot(tenant())) {
            tenancy()->initialize($previousTenant);
        }

        // End tenancy
        if (tenant() && (! $previousTenant)) {
            tenancy()->end();
        }
    }

    protected function setUpPayloadGenerator(): void
    {
        $bootstrapper = &$this;

        if (! $this->queue instanceof QueueFake) {
            $this->queue->createPayloadUsing(function ($connection) use (&$bootstrapper) {
                return $bootstrapper->getPayload($connection);
            });
        }
    }

    public function getPayload(string $connection): array
    {
        if (! tenancy()->initialized) {
            return [];
        }

        if ($this->config["queue.connections.$connection.central"]) {
            return [];
        }

        return [
            'tenant_id' => tenant()->getTenantKey(),
        ];
    }

    public function bootstrap(Tenant $tenant): void {}
    public function revert(): void {}
}
