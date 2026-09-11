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
 * The directory is used by the FilesystemTenancyBootstrapper for:
 * - scoped storage_path() when suffix_storage_path is enabled
 * - scoped cache when enabled
 * - scoped sessions when enabled
 * - scoped disks when enabled
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
        $tenantStoragePath = FilesystemTenancyBootstrapper::getTenantStoragePath($this->tenant);
        $centralStoragePath = FilesystemTenancyBootstrapper::getBoundCentralStoragePath();

        if (realpath($tenantStoragePath) === realpath($centralStoragePath)) {
            // Never delete the central storage directory
            return;
        }

        if (is_dir($tenantStoragePath)) {
            File::deleteDirectory($tenantStoragePath);
        }
    }
}
