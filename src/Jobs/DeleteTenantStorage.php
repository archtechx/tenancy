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

class DeleteTenantStorage implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        public Tenant $tenant,
    ) {}

    public function handle(): void
    {
        if (config('tenancy.filesystem.suffix_storage_path') === false) {
            // Skip storage deletion if path suffixing is disabled
            return;
        }

        $centralStoragePath = tenancy()->central(fn () => storage_path());
        $tenantStoragePath = tenancy()->run($this->tenant, fn () => storage_path());

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
