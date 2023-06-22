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

class QueueTenancyBootstrapper implements TenancyBootstrapper
{
    /** @var Repository */
    protected $config;

    /** @var QueueManager */
    protected $queue;

    /**
     * Don't persist the same tenant across multiple jobs even if they have the same tenant ID.
     *
     * This is useful when you're changing the tenant's state (e.g. properties in the `data` column) and want the next job to initialize tenancy again
     * with the new data. Features like the Tenant Config are only executed when tenancy is initialized, so the re-initialization is needed in some cases.
     *
     * @var bool
     */
    public static $forceRefresh = false;

    /**
     * The normal constructor is only executed after tenancy is bootstrapped.
     * However, we're registering a hook to initialize tenancy. Therefore,
     * we need to register the hook at service provider execution time.
     */
    public static function __constructStatic(Application $app): void
    {
        static::setUpJobListener($app->make(Dispatcher::class));
    }

    public function __construct(Repository $config, QueueManager $queue)
    {
        $this->config = $config;
        $this->queue = $queue;

        $this->setUpPayloadGenerator();
    }

    protected static function setUpJobListener(Dispatcher $dispatcher): void
    {
        $dispatcher->listen(JobProcessing::class, function ($event) {
            static::initializeTenancyForQueue($event->job->payload()['tenant_id'] ?? null);
        });

        $dispatcher->listen(JobRetryRequested::class, function ($event) {
            static::initializeTenancyForQueue($event->payload()['tenant_id'] ?? null);
        });

        // If we're running tests, we make sure to clean up after any artisan('queue:work') calls
        $revertToCentralContext = function ($event) {
            static::revertToCentralContext($event);
        };

        $dispatcher->listen(JobProcessed::class, $revertToCentralContext); // artisan('queue:work') which succeeds
        $dispatcher->listen(JobFailed::class, $revertToCentralContext); // artisan('queue:work') which fails
    }

    protected static function initializeTenancyForQueue(string|int|null $tenantId): void
    {
        if ($tenantId === null) {
            // The job is not tenant-aware
            if (tenancy()->initialized) {
                // Tenancy was initialized, so we revert back to the central context
                tenancy()->end();
            }

            return;
        }

        if (static::$forceRefresh) {
            // Re-initialize tenancy between all jobs
            if (tenancy()->initialized) {
                tenancy()->end();
            }

            /** @var Tenant $tenant */
            $tenant = tenancy()->find($tenantId);
            tenancy()->initialize($tenant);

            return;
        }

        if (tenancy()->initialized) {
            // Tenancy is already initialized
            if (tenant()->getTenantKey() === $tenantId) {
                // It's initialized for the same tenant (e.g. dispatchNow was used, or the previous job also ran for this tenant)
                return;
            }
        }

        // Tenancy was either not initialized, or initialized for a different tenant.
        // Therefore, we initialize it for the correct tenant.

        /** @var Tenant $tenant */
        $tenant = tenancy()->find($tenantId);
        tenancy()->initialize($tenant);
    }

    protected static function revertToCentralContext(JobProcessed|JobFailed $event): void
    {
        $tenantId = $event->job->payload()['tenant_id'] ?? null;

        // The job was not tenant-aware
        if (! $tenantId) {
            return;
        }

        // End tenancy
        if (tenant()) {
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

    public function bootstrap(Tenant $tenant): void
    {
        //
    }

    public function revert(): void
    {
        //
    }

    public function getPayload(string $connection): array
    {
        if (! tenancy()->initialized) {
            return [];
        }

        if ($this->config["queue.connections.$connection.central"]) {
            return [];
        }

        $id = tenant()->getTenantKey();

        return [
            'tenant_id' => $id,
        ];
    }
}
