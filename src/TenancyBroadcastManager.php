<?php

declare(strict_types=1);

namespace Stancl\Tenancy; // todo new Overrides namespace?

use Illuminate\Broadcasting\BroadcastManager;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Broadcasting\Broadcasters\Broadcaster;
use Illuminate\Contracts\Broadcasting\Broadcaster as BroadcasterContract;

class TenancyBroadcastManager extends BroadcastManager
{
    /**
     * Broadcasters to always resolve from the container (even when they're
     * cached and available in the $broadcasters property).
     */
    public static array $tenantBroadcasters = ['pusher', 'ably'];

    /**
     * Override the get method so that the broadcasters in $tenantBroadcasters always get resolved,
     * even when they're cached and available in the $broadcasters property.
     */
    protected function get($name)
    {
        if (in_array($name, static::$tenantBroadcasters)) {
            /** @var Broadcaster|null $cachedBroadcaster */
            $cachedBroadcaster = $this->drivers[$name] ?? null;

            /** @var Broadcaster $broadcaster */
            $broadcaster = $this->resolve($name);


            // If there is a cached broadcaster, give its channels to the newly resolved one
            if ($cachedBroadcaster) {
                $cachedBroadcaster = invade($cachedBroadcaster);

                foreach ($cachedBroadcaster->channels as $channel => $callback) {
                    $broadcaster->channel($channel, $callback, $cachedBroadcaster->retrieveChannelOptions($channel));
                }
            }

            $this->app->singleton(BroadcasterContract::class, fn(Application $app) => $broadcaster);

            return $broadcaster;
        }

        return parent::get($name);
    }

    public function setDriver(string $name, BroadcasterContract $broadcaster): static
    {
        $this->drivers[$name] = $broadcaster;

        return $this;
    }
}
