<?php

declare(strict_types=1);

namespace Stancl\Tenancy\Listeners;

use Illuminate\Support\Facades\File;
use Stancl\Tenancy\Events\Contracts\TenantEvent;

class DeleteTenantStorage
{
    public function handle(TenantEvent $event): void
    {
        $path = tenancy()->run($event->tenant, fn () => storage_path());

        if (is_dir($path)) {
            File::deleteDirectory($path);
        }
    }
}
