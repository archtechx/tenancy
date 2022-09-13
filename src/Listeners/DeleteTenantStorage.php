<?php

declare(strict_types=1);

namespace Stancl\Tenancy\Listeners;

use Illuminate\Support\Facades\File;
use Stancl\Tenancy\Events\TenantDeleted;

class DeleteTenantStorage
{
    public function handle(TenantDeleted $event): void
    {
        File::deleteDirectory($event->tenant->run(fn () => storage_path()));
    }
}
