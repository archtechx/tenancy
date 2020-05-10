<?php

namespace Stancl\Tenancy\Events\Listeners;

use Stancl\Tenancy\Events\TenancyInitialized;

class BootstrapTenancy
{
    public function handle(TenancyInitialized $event)
    {
        foreach ($event->tenancy->getBootstrappers() as $bootstrapper) {
            $bootstrapper->start($event->tenancy->tenant);
        }
    }
}
