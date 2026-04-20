<?php

declare(strict_types=1);

namespace Stancl\Tenancy\Listeners;

use Illuminate\Support\Facades\File;
use Stancl\Tenancy\Events\Contracts\TenantEvent;

/**
 * @deprecated Use Stancl\Tenancy\Jobs\DeleteTenantStorage in a job pipeline instead.
 */
class DeleteTenantStorage
{
    public function handle(TenantEvent $event): void
    {
        if (config('tenancy.filesystem.suffix_storage_path') === false) {
            // Skip storage deletion if path suffixing is disabled
            return;
        }

        $centralStoragePath = tenancy()->central(fn () => storage_path());
        $tenantStoragePath = tenancy()->run($event->tenant, fn () => storage_path());

        if ($tenantStoragePath === $centralStoragePath) {
            // Check again to ensure the tenant storage path is distinct from the central storage path
            // to avoid any accidental central storage path deletion
            return;
        }

        if (is_dir($tenantStoragePath)) {
            File::deleteDirectory($tenantStoragePath);
        }
    }
}
