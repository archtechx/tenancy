<?php

declare(strict_types=1);

namespace Stancl\Tenancy\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Artisan;
use Stancl\Tenancy\Commands\CreatePendingTenants as CreatePendingTenantsCommand;

class CreatePendingTenants implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 3;

    public array $backoff = [30, 60, 120];

    public function handle(): void
    {
        Artisan::call(CreatePendingTenantsCommand::class);
    }
}
