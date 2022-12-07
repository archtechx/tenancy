<?php

declare(strict_types=1);

namespace Stancl\Tenancy; // todo new Overrides namespace?

use Illuminate\Broadcasting\BroadcastManager;
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

            $this->app->forgetInstance(BroadcasterContract::class);

            /** @var Broadcaster $broadcaster */
            $broadcaster = $this->resolve($name);

            if ($cachedBroadcaster) {
                $cachedBroadcaster = invade($cachedBroadcaster);

                foreach ($cachedBroadcaster->channels as $channel => $callback) {
                    $broadcaster->channel($channel, $callback, $cachedBroadcaster->retrieveChannelOptions($channel));
                }
            }

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
