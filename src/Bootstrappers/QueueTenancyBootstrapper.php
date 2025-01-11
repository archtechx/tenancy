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
        $previousTenant = null;

        $dispatcher->listen(JobProcessing::class, function ($event) use (&$previousTenant) {
            $previousTenant = tenant();

            static::initializeTenancyForQueue($event->job->payload()['tenant_id'] ?? null);
        });

        $dispatcher->listen(JobRetryRequested::class, function ($event) use (&$previousTenant) {
            $previousTenant = tenant();

            static::initializeTenancyForQueue($event->payload()['tenant_id'] ?? null);
        });

        $revertToPreviousState = function ($event) use (&$previousTenant) {
            // In queue worker context, this reverts to the central context.
            // In dispatchSync context, this reverts to the previous tenant's context.
            // There's no need to reset $previousTenant here since it's always first
            // set in the above listeners and the app is reverted back to that context.
            static::revertToPreviousState($event->job->payload()['tenant_id'] ?? null, $previousTenant);
        };

        $dispatcher->listen(JobProcessed::class, $revertToPreviousState); // artisan('queue:work') which succeeds
        $dispatcher->listen(JobFailed::class, $revertToPreviousState); // artisan('queue:work') which fails
    }

    protected static function initializeTenancyForQueue(string|int|null $tenantId): void
    {
        if (! $tenantId) {
            return;
        }

        /** @var Tenant $tenant */
        $tenant = tenancy()->find($tenantId);
        tenancy()->initialize($tenant);
    }

    protected static function revertToPreviousState(string|int|null $tenantId, ?Tenant $previousTenant): void
    {
        // The job was not tenant-aware so no context switch was done
        if (! $tenantId) {
            return;
        }

        // End tenancy when there's no previous tenant
        // (= when running in a queue worker, not dispatchSync)
        if (tenant() && ! $previousTenant) {
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
