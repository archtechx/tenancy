<?php

namespace Stancl\Tenancy\Features;

use Laravel\Telescope\Telescope;
use Stancl\Tenancy\TenantManager;
use Laravel\Telescope\IncomingEntry;
use Stancl\Tenancy\Contracts\Feature;

class TelescopeTags implements Feature
{
    public function bootstrap(TenantManager $tenantManager): void
    {
        if (! class_exists(Telescope::class)) {
            return;
        }

        Telescope::tag(function (IncomingEntry $entry) {
            $tags = $this->getTags($entry);

            if (in_array('tenancy', optional(request()->route())->middleware() ?? [])) {
                $tags = array_merge($tags, [
                    'tenant:' . tenant('uuid'),
                    'domain:' . tenant('domain'),
                ]);
            }

            return $tags;
        });
    }

    public function getTags(IncomingEntry $entry): array
    {
        return array_reduce($this->callbacks, function ($tags, $listener) use($entry) {
            return array_merge($tags, $listener($entry));
        }, []);
    }
}