<?php

declare(strict_types=1);

namespace Stancl\Tenancy\Listeners;

use Stancl\Tenancy\Events\Contracts\TenantEvent;

/**
 * Can be used to manually create framework directories in the tenant storage when storage_path() is scoped.
 *
 * Useful when using real-time facades which use the framework/cache directory.
 *
 * Generally not needed anymore as the directory is also created by the FilesystemTenancyBootstrapper.
 */
class CreateTenantStorage
{
    public function handle(TenantEvent $event): void
    {
        $storage_path = tenancy()->run($event->tenant, fn () => storage_path());
        $cache_path = "$storage_path/framework/cache";

        if (! is_dir($cache_path)) {
            // Create the tenant's storage directory and /framework/cache within (used for e.g. real-time facades)
            mkdir($cache_path, 0750, true);
        }
    }
}
