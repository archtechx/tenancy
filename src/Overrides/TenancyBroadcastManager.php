<?php

declare(strict_types=1);

namespace Stancl\Tenancy\Overrides;

use Illuminate\Broadcasting\Broadcasters\Broadcaster;
use Illuminate\Broadcasting\BroadcastManager;
use Illuminate\Contracts\Broadcasting\Broadcaster as BroadcasterContract;

/**
 * BroadcastManager override that always re-resolves the broadcasters in static::$tenantBroadcasters
 * when attempting to retrieve them and passes the channels of the original (central) broadcaster
 * to the newly resolved (tenant) broadcasters.
 *
 * Affects calls that use app(BroadcastManager::class)->get().
 *
 * @see Stancl\Tenancy\Bootstrappers\BroadcastingConfigBootstrapper
 */
class TenancyBroadcastManager extends BroadcastManager
{
    /**
     * Names of broadcasters that
     * - should always be recreated using $this->resolve(), even when they're cached and available
     *   in $this->drivers so that when you update broadcasting config in the tenant context,
     *   the updated config/credentials will be used for broadcasting immediately.
     *   Note that in cases like this, only direct config changes are reflected right away.
     *   For the broadcasters to reflect tenant property changes made in tenant context,
     *   you still have to reinitialize tenancy after updating the tenant properties intended
     *   to be mapped to broadcasting config, since the properties are only mapped to config
     *   on BroadcastingConfigBootstrapper::bootstrap().
     * - should inherit the original broadcaster's channels (= the channels registered in
     *   the central context, e.g. in routes/channels.php, before this manager overrides the bound BroadcastManager).
     */
    public static array $tenantBroadcasters = ['pusher', 'ably'];

    /**
     * Override the get method so that the broadcasters in static::$tenantBroadcasters
     * - receive the original (central) broadcaster's channels
     * - always get freshly resolved.
     */
    protected function get($name)
    {
        if (in_array($name, static::$tenantBroadcasters)) {
            /** @var Broadcaster|null $originalBroadcaster */
            $originalBroadcaster = $this->app->make(BroadcasterContract::class);
            $newBroadcaster = $this->resolve($name);

            // Give the channels of the original (central) broadcaster to the newly resolved one.
            //
            // Broadcasters only have to implement the Illuminate\Contracts\Broadcasting\Broadcaster contract
            // which doesn't require the channels property, so passing the channels is only needed for
            // Illuminate\Broadcasting\Broadcasters\Broadcaster instances (= all the default broadcasters, e.g. PusherBroadcaster).
            if ($originalBroadcaster instanceof Broadcaster && $newBroadcaster instanceof Broadcaster) {
                $this->passChannelsFromOriginalBroadcaster($originalBroadcaster, $newBroadcaster);
            }

            return $newBroadcaster;
        }

        return parent::get($name);
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
