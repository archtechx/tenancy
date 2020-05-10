<?php

namespace Stancl\Tenancy\Events\Listeners;

use Stancl\Tenancy\Events\TenancyEnded;

class RevertToCentralContext
{
    public function handle(TenancyEnded $event)
    {
        foreach ($event->tenancy->getBootstrappers() as $bootstrapper) {
            $bootstrapper->end();
        }
    }   
}
