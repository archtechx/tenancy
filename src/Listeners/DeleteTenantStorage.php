<?php

declare(strict_types=1);

namespace Stancl\Tenancy\Listeners;

use Illuminate\Support\Facades\File;
use Stancl\Tenancy\Events\DeletingTenantStorage;
use Stancl\Tenancy\Events\TenantDeleted;
use Stancl\Tenancy\Events\TenantStorageDeleted;

class DeleteTenantStorage
{
    public function handle(TenantDeleted $event): void
    {
        event(new DeletingTenantStorage($event->tenant));

        File::deleteDirectory($event->tenant->run(fn () => storage_path()));

        event(new TenantStorageDeleted($event->tenant));
    }
}
