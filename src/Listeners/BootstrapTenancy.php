<?php

declare(strict_types=1);

namespace Stancl\Tenancy\Listeners;

use Stancl\Tenancy\Contracts\Tenant;
use Stancl\Tenancy\Events\BootstrappingTenancy;
use Stancl\Tenancy\Events\TenancyBootstrapped;
use Stancl\Tenancy\Events\TenancyInitialized;

class BootstrapTenancy
{
    public function handle(TenancyInitialized $event): void
    {
        event(new BootstrappingTenancy($event->tenancy));

        foreach ($event->tenancy->getBootstrappers() as $bootstrapper) {
            /** @var Tenant $tenant */
            $tenant = $event->tenancy->tenant;

            $bootstrapper->bootstrap($tenant);

            if (! in_array($bootstrapper::class, $event->tenancy->initializedBootstrappers)) {
                $event->tenancy->initializedBootstrappers[] = $bootstrapper::class;
            }
        }

        event(new TenancyBootstrapped($event->tenancy));
    }
}
