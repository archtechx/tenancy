<?php

declare(strict_types=1);

namespace Stancl\Tenancy\TenancyBootstrappers;

use Illuminate\Config\Repository;
use Illuminate\Contracts\Events\Dispatcher;
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

    public function __construct(Repository $config, QueueManager $queue, Dispatcher $event)
    {
        $this->config = $config;
        $this->queue = $queue;
        $this->event = $event;

        $this->setUpJobListener();
        $this->setUpPayloadGenerator();
    }

    protected function setUpJobListener()
    {
        $this->event->listen(JobProcessing::class, function ($event) {
            $tenantId = $event->job->payload()['tenant_id'] ?? null;

            // The job is not tenant-aware
            if (!$tenantId) {
                return;
            }

            // Tenancy is already initialized for the tenant (e.g. dispatchNow was used)
            if (tenancy()->initialized && tenant('id') === $tenantId) {
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

    public function start(Tenant $tenant)
    {
        $this->tenancyInitialized = true;
    }

    public function end()
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

        $id = tenant('id');

        return [
            'tenant_id' => $id,
            'tags' => [
                "tenant:$id",
            ],
        ];
    }
}
