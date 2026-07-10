<?php

declare(strict_types=1);

namespace Stancl\Tenancy\Overrides;

use Illuminate\Broadcasting\Broadcasters\Broadcaster;
use Illuminate\Broadcasting\BroadcastManager;
use Illuminate\Contracts\Broadcasting\Broadcaster as BroadcasterContract;

/**
 * BroadcastManager override that makes the newly resolved (tenant) broadcasters
 * inherit the channels of the original (central) broadcaster.
 *
 * BroadcastingConfigBootstrapper binds a new instance of this manager on each tenancy
 * initialization, so the broadcasters get resolved using the tenant's broadcasting config
 * and stay cached (like in the parent manager) for the duration of the tenant's context.
 *
 * @see Stancl\Tenancy\Bootstrappers\BroadcastingConfigBootstrapper
 */
class TenancyBroadcastManager extends BroadcastManager
{
    /**
     * Resolve the broadcaster and pass it the channels of the currently bound broadcaster
     * (the central one, when the default driver is resolved during bootstrap).
     */
    protected function resolve($name)
    {
        $newBroadcaster = parent::resolve($name);

        /** @var Broadcaster|null $originalBroadcaster */
        $originalBroadcaster = $this->app->make(BroadcasterContract::class);

        // Broadcasters only have to implement the Illuminate\Contracts\Broadcasting\Broadcaster contract
        // which doesn't require the channels property, so we only pass the channels to
        // Illuminate\Broadcasting\Broadcasters\Broadcaster instances (= all the default broadcasters, e.g. PusherBroadcaster).
        if ($originalBroadcaster instanceof Broadcaster && $newBroadcaster instanceof Broadcaster) {
            $this->passChannelsFromOriginalBroadcaster($originalBroadcaster, $newBroadcaster);
        }

        return $newBroadcaster;
    }

    /**
     * The newly resolved broadcasters don't automatically receive the channels registered
     * in central context (e.g. Broadcast::channel() in routes/channels.php), so the channels
     * have to be obtained from the original (central) broadcaster and manually passed to the new broadcasters
     * (broadcasting using a broadcaster with no channels results in a 403 error on Broadcast::auth()).
     */
    protected function passChannelsFromOriginalBroadcaster(Broadcaster $originalBroadcaster, Broadcaster $newBroadcaster): void
    {
        // invade() because channels can't be retrieved through any of the broadcaster's public methods
        $originalBroadcaster = invade($originalBroadcaster);

        foreach ($originalBroadcaster->channels as $channel => $callback) {
            $newBroadcaster->channel($channel, $callback, $originalBroadcaster->retrieveChannelOptions($channel));
        }
    }
}
