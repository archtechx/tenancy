<?php

declare(strict_types=1);

namespace Stancl\Tenancy\Bootstrappers;

use Illuminate\Config\Repository;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Queue\QueueManager;
use Illuminate\Support\Testing\Fakes\QueueFake;
use Stancl\Tenancy\Contracts\TenancyBootstrapper;
use Stancl\Tenancy\Contracts\Tenant;

class QueueTenancyBootstrapper implements TenancyBootstrapper
{
    public $tenancyInitialized = false;

    /** @var Repository */
    protected $config;

    /** @var QueueManager */
    protected $queue;

    /** @var Dispatcher */
    protected $event;

    /**
     * The normal constructor is only executed after tenancy is bootstrapped.
     * However, we're registering a hook to initialize tenancy. Therefore,
     * we need to register the hook at service provider execution time.
     */
    public static function __constructStatic(Application $app)
    {
        static::setUpJobListener($app->make(Dispatcher::class));
    }

    public function __construct(Repository $config, QueueManager $queue)
    {
        $this->config = $config;
        $this->queue = $queue;

        $this->setUpPayloadGenerator();
    }

    protected static function setUpJobListener($dispatcher)
    {
        $dispatcher->listen(JobProcessing::class, function ($event) {
            $tenantId = $event->job->payload()['tenant_id'] ?? null;

            // The job is not tenant-aware
            if (! $tenantId) {
                return;
            }

            // Tenancy is already initialized for the tenant (e.g. dispatchNow was used)
            if (tenancy()->initialized && tenant()->getTenantKey() === $tenantId) {
                return;
            }

            // Tenancy was either not initialized, or initialized for a different tenant.
            // Therefore, we initialize it for the correct tenant.
            tenancy()->initialize(tenancy()->find($tenantId));
        });
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
        $this->tenancyInitialized = true;
    }

    public function revert()
    {
        $this->tenancyInitialized = false;
    }

    public function getPayload(string $connection)
    {
        if (! $this->tenancyInitialized) {
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
