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

    public function __construct(
        protected TenantWithDatabase&Model $tenant,
    ) {}

    /** Skip database deletion if the create_database internal attribute is false. */
    public static bool $skipWhenCreateDatabaseIsFalse = true;

    /** Ignore exceptions thrown during database deletion and continue execution. */
    public static bool $ignoreFailures = false;

    public function handle(): void
    {
        if (static::$skipWhenCreateDatabaseIsFalse && $this->tenant->getInternal('create_database') === false) {
            // If database creation was skipped, we presume deletion should also be skipped.
            // To avoid this skip, either unset the `create_database` attribute (or make it true), or
            // set the $skipWhenCreateDatabaseIsFalse static property to false.
            return;
        }

        event(new DeletingDatabase($this->tenant));

        $deleted = false;

        try {
            $this->tenant->database()->manager()->deleteDatabase($this->tenant);
            $deleted = true;
        } catch (\Throwable $e) {
            if (! static::$ignoreFailures) {
                throw $e;
            }
        }

        if ($deleted) event(new DatabaseDeleted($this->tenant));
    }
}
