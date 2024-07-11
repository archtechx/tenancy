<?php

declare(strict_types=1);

namespace Stancl\Tenancy\Listeners;

use Stancl\Tenancy\Events\TenantCreated;

class CreateTenantStorage
{
    public function handle(TenantCreated $event): void
    {
        $storage_path = tenancy()->run($event->tenant, fn () => storage_path());
        $cache_path = "$storage_path/framework/cache";

        if (! is_dir($cache_path)) {
            // Create the tenant's storage directory and /framework/cache within (used for e.g. real-time facades)
            mkdir($cache_path, 0777, true);
        }
    }
}
