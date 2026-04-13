<?php

declare(strict_types=1);

namespace Stancl\Tenancy\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\File;
use Stancl\Tenancy\Contracts\Tenant;

/**
 * Only delete the tenant storage if storage path suffixing is enabled
 * and the tenant's storage path is different from the central storage path.
 *
 * This is to prevent accidental deletion of the central storage when
 * a tenant's storage path is not properly suffixed.
 */
class DeleteTenantStorage implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        public Tenant $tenant,
    ) {}

    public function handle(): void
    {
        // Skip storage deletion if path suffixing is disabled
        if (config('tenancy.filesystem.suffix_storage_path') === false) {
            return;
        }

        $centralPath = tenancy()->central(fn () => storage_path());
        $path = tenancy()->run($this->tenant, fn () => storage_path());

        // Skip storage deletion if tenant's storage path is the same as central storage path
        $tenantPathIsCentral = realpath($path) === realpath($centralPath);

        if (is_dir($path) && ! $tenantPathIsCentral) {
            File::deleteDirectory($path);
        }
    }
}
