<?php

declare(strict_types=1);

namespace Stancl\Tenancy\Listeners;

use Stancl\Tenancy\Events\RevertedToCentralContext;
use Stancl\Tenancy\Events\RevertingToCentralContext;
use Stancl\Tenancy\Events\TenancyEnded;

class RevertToCentralContext
{
    public function handle(TenancyEnded $event): void
    {
        event(new RevertingToCentralContext($event->tenancy));

        foreach (array_reverse($event->tenancy->getBootstrappers()) as $bootstrapper) {
            $bootstrapper->revert();
        }

        event(new RevertedToCentralContext($event->tenancy));
    }
}
