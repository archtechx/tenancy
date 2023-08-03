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
     * Names of broadcasters to always recreate using $this->resolve() (even when they're
     * cached and available in the $broadcasters property).
     *
     * The reason for recreating the broadcasters is
     * to make your app use the correct broadcaster credentials when tenancy is initialized.
     */
    public static array $tenantBroadcasters = ['pusher', 'ably'];

    /**
     * Override the get method so that the broadcasters in $tenantBroadcasters
     * always get freshly resolved even when they're cached and available in the $broadcasters property,
     * and that the resolved broadcaster will override the BroadcasterContract::class singleton.
     *
     * If there's a cached broadcaster with the same name as $name,
     * give its channels to the newly resolved bootstrapper.
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
