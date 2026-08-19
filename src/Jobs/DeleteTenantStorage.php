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
 * Requires FilesystemTenancyBootstrapper to be enabled, since the tenant storage path
 * is resolved from it.
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
