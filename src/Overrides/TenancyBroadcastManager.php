<?php

declare(strict_types=1);

namespace Stancl\Tenancy\Overrides;

use Illuminate\Broadcasting\Broadcasters\Broadcaster;
use Illuminate\Broadcasting\BroadcastManager;
use Illuminate\Contracts\Broadcasting\Broadcaster as BroadcasterContract;
use Illuminate\Contracts\Foundation\Application;

class TenancyBroadcastManager extends BroadcastManager
{
    /**
     * Names of broadcasters that should always be recreated using $this->resolve()
     * (even when they're cached and available in the $broadcasters property to prevent
     * any potential leaks between contexts) and that should inherit the original broadcaster's channels.
     *
     * The main concern is inheriting the channels, since the channels get registered
     * (e.g. in routes/channels.php) before this manager overrides the BroadcastManager instance
     * and new broadcaster instances don't receive the channels automatically.
     */
    public static array $tenantBroadcasters = ['pusher', 'ably'];

    /**
     * Override the get method so that the broadcasters in $tenantBroadcasters
     * receive the original broadcaster's channels and always get freshly resolved
     * even when they're cached and available in the $broadcasters property,
     * and that the resolved broadcaster will override the BroadcasterContract::class singleton.
     */
    protected function get($name)
    {
        if (in_array($name, static::$tenantBroadcasters)) {
            /** @var Broadcaster|null $originalBroadcaster */
            $originalBroadcaster = $this->app->make(BroadcasterContract::class);
            $newBroadcaster = $this->resolve($name);

            // If there is a current broadcaster, give its channels to the newly resolved one
            // Broadcasters only have to implement the Illuminate\Contracts\Broadcasting\Broadcaster contract
            // Which doesn't require the channels property
            // So passing the channels is only needed for Illuminate\Broadcasting\Broadcasters\Broadcaster instances
            if ($originalBroadcaster instanceof Broadcaster && $newBroadcaster instanceof Broadcaster) {
                $this->passChannelsFromOriginalBroadcaster($originalBroadcaster, $newBroadcaster);
            }

            $this->app->singleton(BroadcasterContract::class, fn (Application $app) => $newBroadcaster);

            return $newBroadcaster;
        }

        return parent::get($name);
    }

    // Because, unlike the original broadcaster, the newly resolved broadcaster won't have the channels registered using routes/channels.php
    // Using it for broadcasting won't work, unless we make it have the original broadcaster's channels
    protected function passChannelsFromOriginalBroadcaster(Broadcaster $originalBroadcaster, Broadcaster $newBroadcaster): void
    {
        // invade() because channels can't be retrieved through any of the broadcaster's public methods
        $originalBroadcaster = invade($originalBroadcaster);

        foreach ($originalBroadcaster->channels as $channel => $callback) {
            $newBroadcaster->channel($channel, $callback, $originalBroadcaster->retrieveChannelOptions($channel));
        }
    }
}
