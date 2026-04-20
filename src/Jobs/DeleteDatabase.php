<?php

declare(strict_types=1);

namespace Stancl\Tenancy\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Stancl\Tenancy\Database\Contracts\TenantWithDatabase;
use Stancl\Tenancy\Events\DatabaseDeleted;
use Stancl\Tenancy\Events\DeletingDatabase;

class DeleteDatabase implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /** The maximum number of times the job may be attempted. */
    public int $tries = 3;

    /** Delay in seconds between retries. */
    public array $backoff = [30, 60, 120];

    public function __construct(
        protected TenantWithDatabase&Model $tenant,
    ) {}

    public function handle(): void
    {
        event(new DeletingDatabase($this->tenant));

        $this->tenant->database()->manager()->deleteDatabase($this->tenant);

        event(new DatabaseDeleted($this->tenant));
    }
}
