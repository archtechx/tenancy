<?php

namespace Stancl\Tenancy\Listeners;

use Stancl\Tenancy\Events\RevertedToCentralContext;
use Stancl\Tenancy\Events\TenancyEnded;

class RevertToCentralContext
{
    public function handle(TenancyEnded $event)
    {
        foreach ($event->tenancy->getBootstrappers() as $bootstrapper) {
            $bootstrapper->revert();
        }

        event(new RevertedToCentralContext($event->tenancy));
    }   
}
