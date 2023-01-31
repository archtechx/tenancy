<?php

declare(strict_types=1);

namespace Stancl\Tenancy\Listeners;

use Illuminate\Support\Facades\File;
use Stancl\Tenancy\Events\DeletingTenant;

class DeleteTenantStorage
{
    public function handle(DeletingTenant $event): void
    {
        // todo@lukas since this is using the 'File' facade instead of low-level PHP functions, Tenancy might affect this?
        //            Therefore, when Tenancy is initialized, this might look INSIDE the tenant's storage, instead of the main storage dir?
        //            The DeletingTenant event will be fired in the central context in 99% of cases, but sometimes it might run in the tenant context (from another tenant) so we want to make sure this works well in all contexts.
        File::deleteDirectory($event->tenant->run(fn () => storage_path()));
    }
}
