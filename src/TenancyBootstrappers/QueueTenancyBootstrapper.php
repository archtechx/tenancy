<?php

declare(strict_types=1);

namespace Stancl\Tenancy\TenancyBootstrappers;

use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\Testing\Fakes\QueueFake;
use Stancl\Tenancy\Contracts\TenancyBootstrapper;
use Stancl\Tenancy\Tenant;

class QueueTenancyBootstrapper implements TenancyBootstrapper
{
    /** @var bool Has tenancy been started. */
    public $started = false;

    /** @var Application */
    protected $app;

    public function __construct(Application $app)
    {
        $this->app = $app;

        $bootstrapper = &$this;

        $queue = $this->app['queue'];
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

        if ($this->app['config']["queue.connections.$connection.tenancy"] === false) {
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
