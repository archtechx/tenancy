<?php

declare(strict_types=1);

namespace Stancl\Tenancy\Features;

use Laravel\Telescope\IncomingEntry;
use Laravel\Telescope\Telescope;
use Stancl\Tenancy\Contracts\Feature;
use Stancl\Tenancy\TenantManager;

class TelescopeTags implements Feature
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
                    'tenant:' . tenant('id'),
                    // 'domain:' . tenant('domain'), todo?
                ]);
            }

            return $tags;
        });
    }

    public function getTags(IncomingEntry $entry): array
    {
        return ($this->callback)($entry);
    }

    public function setCallback(callable $callback)
    {
        $this->callback = $callback;
    }
}
