<?php

declare(strict_types=1);

namespace Stancl\Tenancy\Listeners;

use Illuminate\Contracts\Foundation\Application;
use Stancl\Tenancy\Events\TenancyInitialized;

class BootstrapFeatures
{
    public function __construct(
        protected Application $app
    ) {
    }

    public function handle(TenancyInitialized $event): void
    {
        foreach ($this->app['config']['tenancy.features'] ?? [] as $feature) {
            $this->app[$feature]->bootstrap($event->tenancy);
        }
    }
}
