<?php

declare(strict_types=1);

namespace Stancl\Tenancy\Features;

use Laravel\Telescope\Telescope;
use Stancl\Tenancy\TenantManager;
use Laravel\Telescope\IncomingEntry;
use Stancl\Tenancy\Contracts\FeatureProvider;

class TelescopeTags implements FeatureProvider
{
    /** @var callable User-specific callback that returns tags. */
    protected $callback;

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
        return ($this->callback)($entry);
    }

    // todo name?
    public function tagUsing(callable $callback)
    {
        $this->callback = $callback;
    }
}
