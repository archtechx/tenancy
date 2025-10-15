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
use Stancl\Tenancy\Database\DatabaseManager;
use Stancl\Tenancy\Database\Exceptions\TenantDatabaseAlreadyExistsException;
use Stancl\Tenancy\Database\Exceptions\TenantDatabaseUserAlreadyExistsException;
use Stancl\Tenancy\Events\CreatingDatabase;
use Stancl\Tenancy\Events\DatabaseCreated;

class CreateDatabase implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public static bool $ignoreExisting = false;

    public function __construct(
        protected TenantWithDatabase&Model $tenant,
    ) {}

    public function handle(DatabaseManager $databaseManager): bool
    {
        event(new CreatingDatabase($this->tenant));

        // Terminate execution of this job & other jobs in the pipeline
        if ($this->tenant->getInternal('create_database') === false) {
            return false;
        }

        $this->tenant->database()->makeCredentials();

        try {
            $databaseManager->ensureTenantCanBeCreated($this->tenant);
            $databaseCreated = $this->tenant->database()->manager()->createDatabase($this->tenant);
            assert($databaseCreated);

            event(new DatabaseCreated($this->tenant));
        } catch (TenantDatabaseAlreadyExistsException | TenantDatabaseUserAlreadyExistsException $e) {
            if (! static::$ignoreExisting) {
                throw $e;
            }
        }

        return true;
    }
}
