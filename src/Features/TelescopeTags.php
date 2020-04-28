<?php

declare(strict_types=1);

namespace Stancl\Tenancy\Features;

use Laravel\Telescope\IncomingEntry;
use Laravel\Telescope\Telescope;
use Stancl\Tenancy\Contracts\Feature;
use Stancl\Tenancy\Middleware\PreventAccessFromTenantDomains;
use Stancl\Tenancy\TenantManager;

class TelescopeTags implements Feature
{
    /** @var callable User-specific callback that returns tags. */
    protected $callback;

    public function __construct()
    {
        $this->callback = function ($entry) {
            return [];
        };
    }

    public function bootstrap(TenantManager $tenantManager): void
    {
        if (! class_exists(Telescope::class)) {
            return;
        }

        Telescope::tag(function (IncomingEntry $entry) {
            $tags = $this->getTags($entry);

            if (! request()->route()) {
                return $tags;
            }

            $tenantRoute = PreventAccessFromTenantDomains::routeHasMiddleware(request()->route(), 'tenancy')
                || PreventAccessFromTenantDomains::routeHasMiddleware(request()->route(), 'universal');

            // Don't do anything if we're visiting a universal route on a central domain
            if ($tenantRoute && tenancy()->initialized) {
                $tags = array_merge($tags, [
                    'tenant:' . tenant('id'),
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
