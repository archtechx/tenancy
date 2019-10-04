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
        if (! $queue = $this->app['queue'] instanceof QueueFake) {
            $queue->createPayloadUsing(function () use (&$bootstrapper) {
                return $bootstrapper->getPayload();
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

    public function getPayload()
    {
        if (! $this->started) {
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
