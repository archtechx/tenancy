<?php

declare(strict_types=1);

namespace Stancl\Tenancy\TenancyBootstrappers;

use Illuminate\Contracts\Foundation\Application;
use Stancl\Tenancy\Contracts\TenancyBootstrapper;
use Stancl\Tenancy\Tenant;

class QueueTenancyBootstrapper implements TenancyBootstrapper
{
    /** @var bool Has tenancy been started. */
    protected $started = false;

    /** @var Application */
    protected $app;

    public function __construct(Application $app)
    {
        $this->app = $app;

        $this->app['queue']->createPayloadUsing([$this, 'createPayload']);
        $this->app['events']->listen(\Illuminate\Queue\Events\JobProcessing::class, function ($event) {
            if (\array_key_exists('tenant_id', $event->job->payload())) {
                tenancy()->initById($event->job->payload()['tenant_id']);
            }
        });
    }

    public function start(Tenant $tenant)
    {
        $this->started = true;
    }

    public function end()
    {
        $this->started = false;
    }

    public function createPayload()
    {
        if (! $this->started) {
            return [];
        }

        [$id, $domain] = tenant()->get(['id', 'domain']);

        return [
            'tenant_id' => $id,
            'tags' => [
                "tenant:$id",
                "domain:$domain",
            ],
        ];
    }
}
