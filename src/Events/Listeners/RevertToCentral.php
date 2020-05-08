<?php

namespace Stancl\Tenancy\Events\Listeners;

use Stancl\Tenancy\Events\TenancyEnded;

class RevertToCentral
{
    public function handle(TenancyEnded $event)
    {
        foreach (tenancy()->getBootstrappers() as $bootstrapper) {
            $bootstrapper->end();
        }
    }
}