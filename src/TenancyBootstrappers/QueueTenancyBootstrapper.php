<?php

declare(strict_types=1);

namespace Stancl\Tenancy\TenancyBoostrappers;

use Stancl\Tenancy\Contracts\TenancyBootstrapper;

class QueueTenancyBootstrapper implements TenancyBootstrapper
{
    /** @var bool Has tenancy been started. */
    protected $started = false; // todo var name?

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

    public function start()
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

        [$uuid, $domain] = tenant()->get(['uuid', 'domain']);

        return [
            'tenant_id' => $uuid,
            'tags' => [
                "tenant:$uuid",
                "domain:$domain",
            ],
        ];
    }
}
