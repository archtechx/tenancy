<?php

declare(strict_types=1);

namespace Stancl\Tenancy\Listeners;

use Illuminate\Support\Facades\File;
use Stancl\Tenancy\Events\Contracts\TenantEvent;

/**
 * @deprecated use Stancl\Tenancy\Jobs\DeleteTenantStorage instead.
 */
class DeleteTenantStorage
{
    public function handle(TenantEvent $event): void
    {
        // Skip storage deletion if path suffixing is disabled
        if (config('tenancy.filesystem.suffix_storage_path') === false) {
            return;
        }

        $centralPath = tenancy()->central(fn () => storage_path());
        $path = tenancy()->run($event->tenant, fn () => storage_path());

        // Skip storage deletion if tenant's storage path is the same as central storage path
        $tenantPathIsCentral = realpath($path) === realpath($centralPath);

        if (is_dir($path) && ! $tenantPathIsCentral) {
            File::deleteDirectory($path);
        }
    }
}
