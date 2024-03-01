<?php

declare(strict_types=1);

namespace Stancl\Tenancy\Listeners;

use Stancl\Tenancy\Events\TenantCreated;

class CreateTenantStorage
{
    public function handle(TenantCreated $event): void
    {
        $storage_path = tenancy()->run($event->tenant, fn () => storage_path());

        mkdir("$storage_path", 0777, true); // Create the tenant's folder inside storage/
        mkdir("$storage_path/framework/cache", 0777, true); // Create /framework/cache inside the tenant's storage (used for e.g. real-time facades)
    }
}
