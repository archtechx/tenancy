<?php

declare(strict_types=1);

namespace Stancl\Tenancy\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Artisan;
use Stancl\Tenancy\Contracts\Tenant;
use Stancl\Tenancy\Events\CreatingStorageSymlink;
use Stancl\Tenancy\Events\StorageSymlinkCreated;

class CreateStorageSymlinks implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public Tenant $tenant;

    /**
     * Create a new job instance.
     *
     * @return void
     */
    public function __construct(Tenant $tenant)
    {
        $this->tenant = $tenant;
    }

    /**
     * Execute the job.
     *
     * @return void
     */
    public function handle()
    {
        event(new CreatingStorageSymlink($this->tenant));

        Artisan::call('tenants:link', [
            '--tenants' => [$this->tenant->getTenantKey()],
        ]);

        event(new StorageSymlinkCreated($this->tenant));
    }
}
