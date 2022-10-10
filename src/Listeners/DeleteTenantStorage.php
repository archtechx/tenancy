<?php

declare(strict_types=1);

namespace Stancl\Tenancy\Listeners;

use Illuminate\Support\Facades\File;
use Stancl\Tenancy\Events\DeletingTenant;

class DeleteTenantStorage
{
    public function handle(DeletingTenant $event): void
    {
        File::deleteDirectory($event->tenant->run(fn () => storage_path()));
    }
}
