<?php

declare(strict_types=1);

namespace Stancl\Tenancy\Bootstrappers;

use Illuminate\Support\Str;
use Illuminate\Config\Repository;
use Illuminate\Queue\QueueManager;
use Stancl\Tenancy\Contracts\Tenant;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Queue\Events\JobRetryRequested;
use Illuminate\Support\Testing\Fakes\QueueFake;
use Illuminate\Contracts\Foundation\Application;
use Stancl\Tenancy\Contracts\TenancyBootstrapper;

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
    public static function __constructStatic(Application $app)
    {
        static::setUpJobListener($app->make(Dispatcher::class), $app->runningUnitTests());
    }

    public function __construct(Repository $config, QueueManager $queue)
    {
        $this->config = $config;
        $this->queue = $queue;

        $this->setUpPayloadGenerator();
    }

    protected static function setUpJobListener($dispatcher, $runningTests)
    {
        $previousTenant = null;

        $dispatcher->listen(JobProcessing::class, function ($event) use (&$previousTenant) {
            $previousTenant = tenant();

            static::initializeTenancyForQueue($event->job->payload()['tenant_id'] ?? null);
        });

        if (Str::startsWith(app()->version(), '8')) {
            // JobRetryRequested only exists since Laravel 8
            $dispatcher->listen(JobRetryRequested::class, function ($event) use (&$previousTenant) {
                $previousTenant = tenant();

                static::initializeTenancyForQueue($event->payload()['tenant_id'] ?? null);
            });
        }

        // If we're running tests, we make sure to clean up after any artisan('queue:work') calls
        $revertToPreviousState = function ($event) use (&$previousTenant, $runningTests) {
            if ($runningTests) {
                static::revertToPreviousState($event, $previousTenant);
            }
        };

        $dispatcher->listen(JobProcessed::class, $revertToPreviousState); // artisan('queue:work') which succeeds
        $dispatcher->listen(JobFailed::class, $revertToPreviousState); // artisan('queue:work') which fails
    }

    protected static function initializeTenancyForQueue($tenantId)
    {
        // The job is not tenant-aware
        if (! $tenantId) {
            return;
        }

        if (tenancy()->initialized) {
            if (tenant()->getTenantKey() === $tenantId) {
                // Tenancy is already initialized for the tenant (e.g. dispatchNow was used)
                return;
            }
        }

        // Tenancy was either not initialized, or initialized for a different tenant.
        // Therefore, we initialize it for the correct tenant.
        tenancy()->initialize(tenancy()->find($tenantId));
    }

    protected static function revertToPreviousState($event, &$previousTenant)
    {
        $tenantId = $event->job->payload()['tenant_id'] ?? null;

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

    protected function setUpPayloadGenerator()
    {
        $bootstrapper = &$this;

        if (! $this->queue instanceof QueueFake) {
            $this->queue->createPayloadUsing(function ($connection) use (&$bootstrapper) {
                return $bootstrapper->getPayload($connection);
            });
        }
    }

    public function bootstrap(Tenant $tenant)
    {
        //
    }

    public function revert()
    {
        //
    }

    public function getPayload(string $connection)
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
