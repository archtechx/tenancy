<?php

declare(strict_types=1);

namespace Stancl\Tenancy\TenancyBootstrappers;

use Illuminate\Config\Repository;
use Illuminate\Queue\QueueManager;
use Illuminate\Support\Testing\Fakes\QueueFake;
use Stancl\Tenancy\Contracts\TenancyBootstrapper;
use Stancl\Tenancy\Tenant;

class QueueTenancyBootstrapper implements TenancyBootstrapper
{
    /** @var bool Has tenancy been started. */
    public $started = false;

    /** @var Repository */
    protected $config;

    public function __construct(Repository $config, QueueManager $queue)
    {
        $this->config = $config;

        $bootstrapper = &$this;

        if (! $queue instanceof QueueFake) {
            $queue->createPayloadUsing(function ($connection) use (&$bootstrapper) {
                return $bootstrapper->getPayload($connection);
            });
        }
    }

    public function start(Tenant $tenant)
    {
        $this->started = true;
    }

    public function end()
    {
        $this->started = false;
    }

    public function getPayload(string $connection)
    {
        if (! $this->started) {
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
