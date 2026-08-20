<?php

declare(strict_types=1);

namespace Stancl\Tenancy\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\File;
use Stancl\Tenancy\Bootstrappers\FilesystemTenancyBootstrapper;
use Stancl\Tenancy\Contracts\Tenant;

/**
 * Delete the tenant's storage directory.
 *
 * The directory is the one FilesystemTenancyBootstrapper scopes the tenant's disks, cache
 * and sessions to. The path is derived the same way the bootstrapper derives it, so the
 * bootstrapper doesn't *have* to be enabled (though if it isn't, nothing was written there and
 * there's nothing to delete).
 *
 * Files outside that directory (e.g. disks with an %original_storage_path%-based
 * root_override) are not deleted.
 *
 * Note that this job is not affected by the tenancy.filesystem.suffix_storage_path config
 * since it doesn't use the storage_path() helper.
 *
 * @see FilesystemTenancyBootstrapper
 */
class DeleteTenantStorage implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        public Tenant $tenant,
    ) {}

    public function handle(): void
    {
        $tenantStoragePath = FilesystemTenancyBootstrapper::getBoundTenantStoragePath($this->tenant);
        $centralStoragePath = FilesystemTenancyBootstrapper::getBoundCentralStoragePath();

        if (realpath($tenantStoragePath) === realpath($centralStoragePath)) {
            // Never delete the central storage directory -- that would delete the files of all tenants
            return;
        }

        if (is_dir($tenantStoragePath)) {
            File::deleteDirectory($tenantStoragePath);
        }
    }
}
