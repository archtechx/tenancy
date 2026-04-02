<?php

declare(strict_types=1);

namespace Stancl\Tenancy\Overrides;

use Illuminate\Broadcasting\Broadcasters\Broadcaster;
use Illuminate\Broadcasting\BroadcastManager;
use Illuminate\Contracts\Broadcasting\Broadcaster as BroadcasterContract;

class TenancyBroadcastManager extends BroadcastManager
{
    /**
     * Names of broadcasters that
     * - should always be recreated using $this->resolve(), even when they're cached and available
     *   in $this->drivers (so that e.g. when you update tenant's broadcaster credentials in the tenant context,
     *   the updated credentials will be used for broadcasting in the same context)
     * - should inherit the original broadcaster's channels (= the channels registered in
     *   the central context, e.g. in routes/channels.php, before this manager overrides the bound BroadcastManager).
     */
    public static array $tenantBroadcasters = ['pusher', 'ably'];

    /**
     * Override the get method so that the broadcasters in static::$tenantBroadcasters
     * receive the original broadcaster's channels and always get freshly resolved.
     */
    protected function get($name)
    {
        if (in_array($name, static::$tenantBroadcasters)) {
            /** @var Broadcaster|null $originalBroadcaster */
            $originalBroadcaster = $this->app->make(BroadcasterContract::class);
            $newBroadcaster = $this->resolve($name);

            // Give the channels of the original broadcaster (from the central context) to the newly resolved one.
            // Broadcasters only have to implement the Illuminate\Contracts\Broadcasting\Broadcaster contract
            // which doesn't require the channels property, so passing the channels is only
            // needed for Illuminate\Broadcasting\Broadcasters\Broadcaster instances.
            if ($originalBroadcaster instanceof Broadcaster && $newBroadcaster instanceof Broadcaster) {
                $this->passChannelsFromOriginalBroadcaster($originalBroadcaster, $newBroadcaster);
            }

            return $newBroadcaster;
        }

        return parent::get($name);
    }

    // The newly resolved broadcasters don't automatically receive the channels registered
    // in central context (e.g. in routes/channels.php), so we have to obtain the channels from the
    // broadcaster used in central context and manually pass them to the new broadcasters
    // (attempting to broadcast using a broadcaster with no channels results in a 403 error).
    protected function passChannelsFromOriginalBroadcaster(Broadcaster $originalBroadcaster, Broadcaster $newBroadcaster): void
    {
        // invade() because channels can't be retrieved through any of the broadcaster's public methods
        $originalBroadcaster = invade($originalBroadcaster);

        foreach ($originalBroadcaster->channels as $channel => $callback) {
            $newBroadcaster->channel($channel, $callback, $originalBroadcaster->retrieveChannelOptions($channel));
        }
    }
}
