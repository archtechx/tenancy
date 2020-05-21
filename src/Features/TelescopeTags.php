<?php

declare(strict_types=1);

namespace Stancl\Tenancy\Features;

use Laravel\Telescope\IncomingEntry;
use Laravel\Telescope\Telescope;
use Stancl\Tenancy\Contracts\Feature;
use Stancl\Tenancy\Tenancy;

class TelescopeTags implements Feature
{
    /** @var callable User-specific callback that returns tags. */
    public static $getTagsUsing;

    public function bootstrap(Tenancy $tenancy): void
    {
        if (! class_exists(Telescope::class)) {
            return;
        }

        Telescope::tag(function (IncomingEntry $entry) {
            $tags = $this->getTags($entry);

            if (! request()->route()) {
                return $tags;
            }

            if (tenancy()->initialized) {
                $tags = array_merge($tags, [
                    'tenant:' . tenant('id'),
                ]);
            }

            return $tags;
        });
    }

    public static function getTags(IncomingEntry $entry): array
    {
        $callback = static::$getTagsUsing ?? function () {
            return [];
        };

        return $callback($entry);
    }
}