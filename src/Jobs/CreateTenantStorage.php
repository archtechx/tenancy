<?php

declare(strict_types=1);

namespace Stancl\Tenancy\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Stancl\Tenancy\Contracts\Tenant;

/**
 * Can be used to manually create framework directories in the tenant storage when storage_path() is scoped.
 *
 * Useful when using real-time facades which use the framework/cache directory.
 *
 * Generally not needed anymore as the directory is also created by the FilesystemTenancyBootstrapper.
 */
class CreateTenantStorage implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        public Tenant $tenant,
    ) {}

    public function handle(): void
    {
        $storage_path = tenancy()->run($this->tenant, fn () => storage_path());
        $cache_path = "$storage_path/framework/cache";

        if (! is_dir($cache_path)) {
            // Create the tenant's storage directory and /framework/cache within (used for e.g. real-time facades)
            mkdir($cache_path, 0750, true);
        }
    }
}
