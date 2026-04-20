<?php

declare(strict_types=1);

namespace Stancl\Tenancy\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Stancl\Tenancy\Actions\CreateStorageSymlinksAction;
use Stancl\Tenancy\Contracts\Tenant;

class CreateStorageSymlinks implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /** The maximum number of times the job may be attempted. */
    public int $tries = 3;

    /** Delay in seconds between retries. */
    public array $backoff = [30, 60, 120];

    public function __construct(
        public Tenant $tenant,
    ) {}

    public function handle(): void
    {
        (new CreateStorageSymlinksAction)($this->tenant);
    }
}
